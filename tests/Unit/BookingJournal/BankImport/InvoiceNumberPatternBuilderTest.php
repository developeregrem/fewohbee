<?php

declare(strict_types=1);

namespace App\Tests\Unit\BookingJournal\BankImport;

use App\Service\BookingJournal\BankImport\InvoiceNumberPatternBuilder;
use App\Service\InvoiceNumberPatternService;
use PHPUnit\Framework\TestCase;

final class InvoiceNumberPatternBuilderTest extends TestCase
{
    private InvoiceNumberPatternBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new InvoiceNumberPatternBuilder(new InvoiceNumberPatternService());
    }

    public function testEmptyPatternListYieldsEmptyMatcher(): void
    {
        $matcher = $this->builder->buildFromPatterns([]);

        self::assertTrue($matcher->isEmpty());
        self::assertSame([], $matcher->extractCandidates('Zahlung RE-2026-0001'));
    }

    public function testUnusablePatternIsSkipped(): void
    {
        // No <number> placeholder: cannot be compiled, must not become a broken regex.
        $matcher = $this->builder->buildFromPatterns(['RE-<year>', '   ']);

        self::assertTrue($matcher->isEmpty());
    }

    public function testExtractsNumberOfConfiguredRange(): void
    {
        $matcher = $this->builder->buildFromPatterns(['RE-<year>-<number:4>']);

        self::assertSame(
            ['RE-2026-0042'],
            $matcher->extractCandidates('Ueberweisung RE-2026-0042 vielen Dank')
        );
    }

    public function testDoesNotMatchInsideLongerToken(): void
    {
        $matcher = $this->builder->buildFromPatterns(['RE-<year>-<number:4>']);

        self::assertSame([], $matcher->extractCandidates('Referenz CORE-2026-00421X'));
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $matcher = $this->builder->buildFromPatterns(['RE-<year>-<number:4>']);

        self::assertSame(['re-2026-0042'], $matcher->extractCandidates('zahlung re-2026-0042'));
    }

    public function testSequenceMayOutgrowItsPadding(): void
    {
        $matcher = $this->builder->buildFromPatterns(['RE-<year>-<number:4>']);

        self::assertSame(['RE-2026-10000'], $matcher->extractCandidates('RE-2026-10000'));
    }

    public function testInvalidMonthIsNotMatched(): void
    {
        $matcher = $this->builder->buildFromPatterns(['<year><month>-<number:3>']);

        self::assertSame([], $matcher->extractCandidates('Beleg 202699-001'));
        self::assertSame(['202608-001'], $matcher->extractCandidates('Beleg 202608-001'));
    }

    public function testSeveralRangesAreUnioned(): void
    {
        $matcher = $this->builder->buildFromPatterns([
            'NORD-<year>-<number:4>',
            'SUED-<year>-<number:4>',
        ]);

        self::assertCount(2, $matcher->regexes);
        self::assertSame(
            ['NORD-2026-0001', 'SUED-2026-0007'],
            $matcher->extractCandidates('Sammelzahlung NORD-2026-0001 und SUED-2026-0007')
        );
    }

    public function testIdenticalPatternsAreDeduplicated(): void
    {
        $matcher = $this->builder->buildFromPatterns([
            '<year>-<number:4>',
            '<year>-<number:4>',
        ]);

        self::assertCount(1, $matcher->regexes);
        self::assertCount(1, $matcher->examples);
    }

    public function testExamplesRenderTheFirstNumberOfTheRange(): void
    {
        $matcher = $this->builder->buildFromPatterns(['RE-<year>-<number:4>']);
        $year = (new \DateTimeImmutable())->format('Y');

        self::assertSame(['RE-'.$year.'-0001'], $matcher->examples);
        self::assertSame('RE-'.$year.'-0001', $this->builder->describe($matcher));
    }
}
