<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AppSettings;
use App\Entity\Subsidiary;
use App\Repository\InvoiceRepository;
use App\Repository\SubsidiaryRepository;
use App\Service\AppSettingsService;
use App\Service\InvoiceNumberGenerator;
use App\Service\InvoiceNumberPatternService;
use PHPUnit\Framework\TestCase;

final class InvoiceNumberGeneratorTest extends TestCase
{
    private \DateTimeImmutable $date;

    protected function setUp(): void
    {
        $this->date = new \DateTimeImmutable('2026-08-17');
    }

    public function testStartsAtOneWhenTheRangeIsEmpty(): void
    {
        $generator = $this->createGenerator('RE-<year>-<number:4>', [], []);

        self::assertSame('RE-2026-0001', $generator->generateNext(null, $this->date));
    }

    public function testContinuesFromTheHighestExistingNumber(): void
    {
        $generator = $this->createGenerator('RE-<year>-<number:4>', [], [
            'RE-2026-0001', 'RE-2026-0042', 'RE-2026-0007',
        ]);

        self::assertSame('RE-2026-0043', $generator->generateNext(null, $this->date));
    }

    public function testIgnoresUnparseableNumbersInsideTheRange(): void
    {
        $generator = $this->createGenerator('RE-<year>-<number:4>', [], [
            'RE-2026-0003', 'RE-2026-storno',
        ]);

        self::assertSame('RE-2026-0004', $generator->generateNext(null, $this->date));
    }

    public function testBranchPatternWinsOverTheGlobalDefault(): void
    {
        $generator = $this->createGenerator('RE-<year>-<number:4>', [], []);
        $branch = $this->createSubsidiary(1, 'NORD-<year>-<number:4>');

        self::assertSame('NORD-2026-0001', $generator->generateNext($branch, $this->date));
    }

    public function testBranchWithoutOwnPatternFallsBackToTheGlobalDefault(): void
    {
        $generator = $this->createGenerator('RE-<year>-<number:4>', [], []);
        $branch = $this->createSubsidiary(1, null);

        self::assertSame('RE-2026-0001', $generator->generateNext($branch, $this->date));
    }

    public function testReturnsNullWhenNothingIsConfigured(): void
    {
        $generator = $this->createGenerator(null, [], []);

        self::assertNull($generator->generateNext(null, $this->date));
        self::assertNull($generator->resolvePattern(null));
        self::assertFalse($generator->hasConfiguredPattern());
    }

    public function testSkipsANumberThatIsAlreadyTaken(): void
    {
        // The range only knows 0001, but 0002 was entered by hand outside the range's
        // LIKE window (e.g. re-numbered later), so it must not be handed out twice.
        $generator = $this->createGenerator(
            'RE-<year>-<number:4>',
            [],
            ['RE-2026-0001'],
            takenNumbers: ['RE-2026-0002'],
        );

        self::assertSame('RE-2026-0003', $generator->generateNext(null, $this->date));
    }

    public function testAllConfiguredPatternsUnionsGlobalAndBranches(): void
    {
        $generator = $this->createGenerator(
            'RE-<year>-<number:4>',
            ['NORD-<year>-<number:4>', 'SUED-<year>-<number:4>'],
            []
        );

        self::assertSame(
            ['RE-<year>-<number:4>', 'NORD-<year>-<number:4>', 'SUED-<year>-<number:4>'],
            $generator->allConfiguredPatterns()
        );
        self::assertTrue($generator->hasConfiguredPattern());
    }

    public function testAllConfiguredPatternsDropsDuplicatesAndUnusableEntries(): void
    {
        $generator = $this->createGenerator(
            'RE-<year>-<number:4>',
            ['RE-<year>-<number:4>', 'RE-<year>'],
            []
        );

        self::assertSame(['RE-<year>-<number:4>'], $generator->allConfiguredPatterns());
    }

    // ── Test helpers ─────────────────────────────────────────────────

    /**
     * @param list<string> $branchPatterns
     * @param list<string> $numbersInRange numbers the range query returns
     * @param list<string> $takenNumbers   additional numbers countByNumber() reports as used
     */
    private function createGenerator(
        ?string $globalPattern,
        array $branchPatterns,
        array $numbersInRange,
        array $takenNumbers = [],
    ): InvoiceNumberGenerator {
        $settings = new AppSettings();
        $settings->setInvoiceNumberPattern($globalPattern);

        $appSettingsService = $this->createStub(AppSettingsService::class);
        $appSettingsService->method('getSettings')->willReturn($settings);

        $subsidiaryRepo = $this->createStub(SubsidiaryRepository::class);
        $subsidiaryRepo->method('findConfiguredPatterns')->willReturn($branchPatterns);

        $used = array_merge($numbersInRange, $takenNumbers);
        $invoiceRepo = $this->createStub(InvoiceRepository::class);
        $invoiceRepo->method('findNumbersInRange')->willReturn($numbersInRange);
        $invoiceRepo->method('countByNumber')->willReturnCallback(
            static fn (string $number): int => in_array($number, $used, true) ? 1 : 0
        );

        return new InvoiceNumberGenerator(
            $appSettingsService,
            $subsidiaryRepo,
            $invoiceRepo,
            new InvoiceNumberPatternService(),
        );
    }

    private function createSubsidiary(int $id, ?string $pattern): Subsidiary
    {
        $subsidiary = new Subsidiary();
        $subsidiary->setId($id);
        $subsidiary->setName('Test');
        $subsidiary->setInvoiceNumberPattern($pattern);

        return $subsidiary;
    }
}
