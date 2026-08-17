<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Exception\InvalidInvoiceNumberPatternException;
use App\Service\InvoiceNumberPatternService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoiceNumberPatternServiceTest extends TestCase
{
    private InvoiceNumberPatternService $service;
    private \DateTimeImmutable $date;

    protected function setUp(): void
    {
        $this->service = new InvoiceNumberPatternService();
        $this->date = new \DateTimeImmutable('2026-08-17');
    }

    #[DataProvider('renderProvider')]
    public function testRender(string $pattern, int $sequence, string $expected): void
    {
        self::assertSame($expected, $this->service->compile($pattern)->render($this->date, $sequence));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function renderProvider(): array
    {
        return [
            'literal prefix and year' => ['RE-<year>-<number:4>', 42, 'RE-2026-0042'],
            'bare number defaults to width 4' => ['<number>', 7, '0007'],
            'two digit year' => ['<year2>-<number:3>', 5, '26-005'],
            'month' => ['<year><month>-<number:2>', 9, '202608-09'],
            'day' => ['<year><month><day>-<number:1>', 3, '20260817-3'],
            'number in the middle' => ['RE-<number:4>-<year>', 42, 'RE-0042-2026'],
            'sequence outgrows padding' => ['RE-<year>-<number:4>', 10000, 'RE-2026-10000'],
        ];
    }

    #[DataProvider('likeProvider')]
    public function testLikePattern(string $pattern, string $expected): void
    {
        self::assertSame($expected, $this->service->compile($pattern)->likePattern($this->date));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function likeProvider(): array
    {
        return [
            'trailing sequence' => ['RE-<year>-<number:4>', 'RE-2026-%'],
            'sequence in the middle' => ['RE-<number:4>-<year>', 'RE-%-2026'],
            'sequence only' => ['<number:4>', '%'],
            'percent in literal is escaped' => ['RE%<year>-<number>', 'RE\%2026-%'],
            'underscore in literal is escaped' => ['RE_<year>-<number>', 'RE\_2026-%'],
        ];
    }

    public function testExtractSequenceRoundTrip(): void
    {
        $pattern = $this->service->compile('RE-<year>-<number:4>');

        self::assertSame(42, $pattern->extractSequence($pattern->render($this->date, 42), $this->date));
    }

    public function testExtractSequenceRejectsAnotherPeriod(): void
    {
        $pattern = $this->service->compile('RE-<year>-<number:4>');

        self::assertNull($pattern->extractSequence('RE-2025-0042', $this->date));
    }

    public function testExtractSequenceRejectsForeignFormat(): void
    {
        $pattern = $this->service->compile('RE-<year>-<number:4>');

        self::assertNull($pattern->extractSequence('RE-2026-storno', $this->date));
        self::assertNull($pattern->extractSequence('XY-2026-0042', $this->date));
    }

    public function testRegexDoesNotMatchInsideLongerToken(): void
    {
        $regex = $this->service->compile('RE-<year>-<number:4>')->toRegex();

        self::assertSame(0, preg_match($regex, 'CORE-2026-00421X'));
        self::assertSame(1, preg_match($regex, 'Zahlung RE-2026-0042 danke'));
    }

    public function testRegexWorksWhenPatternEndsWithSeparator(): void
    {
        // A trailing '/' would defeat \b, which is why lookarounds are used instead.
        $regex = $this->service->compile('<year>/<number:4>/')->toRegex();

        self::assertSame(1, preg_match($regex, 'Beleg 2026/0042/ bezahlt'));
    }

    public function testYearRollsOverIntoAFreshRange(): void
    {
        $pattern = $this->service->compile('RE-<year>-<number:4>');

        self::assertSame('RE-2026-%', $pattern->likePattern(new \DateTimeImmutable('2026-12-31')));
        self::assertSame('RE-2027-%', $pattern->likePattern(new \DateTimeImmutable('2027-01-01')));
    }

    #[DataProvider('errorProvider')]
    public function testInvalidPatternsAreRejected(string $pattern, string $expectedKey): void
    {
        $findings = $this->service->validate($pattern);

        self::assertNotEmpty($findings);
        self::assertSame($expectedKey, $findings[0]['key']);
        self::assertSame('error', $findings[0]['severity']);
        self::assertFalse($this->service->isValid($pattern));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function errorProvider(): array
    {
        return [
            'no number' => ['RE-<year>', 'invoice_number_pattern.missing_number'],
            'two numbers' => ['A-<number>-<number>', 'invoice_number_pattern.duplicate_number'],
            'unknown placeholder' => ['RE-<jahr>-<number>', 'invoice_number_pattern.unknown_placeholder'],
            'width too large' => ['<number:99>', 'invoice_number_pattern.invalid_width'],
            'width on a date placeholder' => ['<year:2>-<number>', 'invoice_number_pattern.width_not_allowed'],
        ];
    }

    public function testRenderedNumberLongerThanTheColumnIsRejected(): void
    {
        $findings = $this->service->validate(str_repeat('X', 40).'-<number:8>');

        self::assertSame('invoice_number_pattern.too_long', $findings[0]['key']);
        self::assertSame('error', $findings[0]['severity']);
    }

    public function testMonthWithoutYearIsOnlyAWarning(): void
    {
        $findings = $this->service->validate('<month>-<number:4>');

        self::assertCount(1, $findings);
        self::assertSame('invoice_number_pattern.month_without_year', $findings[0]['key']);
        self::assertSame('warning', $findings[0]['severity']);
        self::assertTrue($this->service->isValid('<month>-<number:4>'), 'A warning must not block saving.');
    }

    public function testEmptyPatternIsNotAnError(): void
    {
        // Empty means "not configured" and falls back to the global default.
        self::assertSame([], $this->service->validate(''));
        self::assertSame([], $this->service->validate('   '));
        self::assertTrue($this->service->isValid(''));
    }

    public function testTryCompileSwallowsInvalidInput(): void
    {
        self::assertNull($this->service->tryCompile(null));
        self::assertNull($this->service->tryCompile(''));
        self::assertNull($this->service->tryCompile('RE-<year>'));
        self::assertNotNull($this->service->tryCompile('RE-<year>-<number:4>'));
    }

    public function testCompileThrowsWithTheTranslationKey(): void
    {
        $this->expectException(InvalidInvoiceNumberPatternException::class);

        try {
            $this->service->compile('RE-<jahr>-<number>');
        } catch (InvalidInvoiceNumberPatternException $e) {
            self::assertSame('invoice_number_pattern.unknown_placeholder', $e->getTranslationKey());
            self::assertSame(['%placeholder%' => '<jahr>'], $e->getTranslationParameters());

            throw $e;
        }
    }

    public function testExampleShowsTheFirstNumberOfTheRange(): void
    {
        self::assertSame('RE-2026-0001', $this->service->compile('RE-<year>-<number:4>')->example($this->date));
    }
}
