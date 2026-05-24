<?php

declare(strict_types=1);

namespace App\Tests\Unit\BookingJournal\BankImport;

use App\Service\BookingJournal\BankImport\InvoiceNumberPatternBuilder;
use PHPUnit\Framework\TestCase;

final class InvoiceNumberPatternBuilderTest extends TestCase
{
    private InvoiceNumberPatternBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new InvoiceNumberPatternBuilder();
    }

    public function testEmptySamplesProducesEmptyMatcher(): void
    {
        $matcher = $this->builder->buildFromSamples([]);
        self::assertTrue($matcher->isEmpty());
        self::assertSame([], $matcher->extractCandidates('beliebiger Text'));
    }

    public function testWhitespaceOnlySamplesAreIgnored(): void
    {
        $matcher = $this->builder->buildFromSamples(['   ', '']);
        self::assertTrue($matcher->isEmpty());
    }

    public function testPrefixedSampleMatchesSameShape(): void
    {
        $matcher = $this->builder->buildFromSamples(['RE-12345']);

        $candidates = $matcher->extractCandidates('Rechnung RE-12345 vom 31.03.2026');
        self::assertSame(['RE-12345'], $candidates);

        $candidates = $matcher->extractCandidates('Rechnung RE-9 zu zahlen');
        self::assertSame(['RE-9'], $candidates);

        $candidates = $matcher->extractCandidates('Rechnung RE-99999999 zu zahlen');
        self::assertSame(['RE-99999999'], $candidates);
    }

    public function testWordBoundariesPreventCorePrefixCollision(): void
    {
        $matcher = $this->builder->buildFromSamples(['RE-12345']);

        // "CORE-1234567" should NOT match — boundary stops "RE-…" from being
        // part of a longer token.
        self::assertSame([], $matcher->extractCandidates('Mandat: CORE-1234567 erteilt'));
    }

    public function testPrefixMatchIsCaseInsensitive(): void
    {
        $matcher = $this->builder->buildFromSamples(['RE-12345']);

        $candidates = $matcher->extractCandidates('siehe re-7777 für details');
        self::assertSame(['re-7777'], $candidates);
    }

    public function testYearPrefixedSampleMatches(): void
    {
        $matcher = $this->builder->buildFromSamples(['2026-0001']);

        self::assertSame(['2026-0001'], $matcher->extractCandidates('Rechnung 2026-0001'));
        self::assertSame(['2026-9999'], $matcher->extractCandidates('Rechnung 2026-9999 vom 31.03.'));
        self::assertSame(['2025-0042'], $matcher->extractCandidates('Bezug auf 2025-0042'));
    }

    public function testPureNumericSampleFlagsStrictExistenceCheck(): void
    {
        $matcher = $this->builder->buildFromSamples(['12345']);

        self::assertTrue($matcher->requiresStrictExistenceCheck);
        // It still extracts candidates — but the consumer must verify them.
        self::assertSame(['12345'], $matcher->extractCandidates('Rechnung 12345'));
    }

    public function testPureNumericSampleDoesNotMatchShorterDigitRuns(): void
    {
        // Regression: 5-digit invoice samples used to compile to \d{1,9} and
        // wrongly matched "102" inside "KOMMISSION 102,33" on Booking.com
        // pay-out lines.
        $matcher = $this->builder->buildFromSamples(['12345']);

        $purpose = 'BOOKING.COM BV SAMMELAUSZAHLUNG APRIL 2026 3 BUCHUNGEN KOMMISSION 102,33 GEBUEHR 7,45';
        self::assertSame([], $matcher->extractCandidates($purpose));
    }

    public function testPureNumericMatchesObservedAndSlightlyLongerLengths(): void
    {
        $matcher = $this->builder->buildFromSamples(['12345']);

        // Same length matches.
        self::assertSame(['67890'], $matcher->extractCandidates('Bezug 67890'));
        // One digit more is still within the +2 tolerance.
        self::assertSame(['123456'], $matcher->extractCandidates('Bezug 123456'));
        // Three digits less is out of bounds — would have falsely matched before.
        self::assertSame([], $matcher->extractCandidates('Bezug 102'));
    }

    public function testMixedSampleDoesNotFlagStrictCheck(): void
    {
        $matcher = $this->builder->buildFromSamples(['RE-12345']);

        self::assertFalse($matcher->requiresStrictExistenceCheck);
    }

    public function testMultipleSamplesProduceUnionMatcher(): void
    {
        $matcher = $this->builder->buildFromSamples(['RE-12345', '2026-0001']);

        self::assertSame(['RE-7'], $matcher->extractCandidates('Bezug RE-7'));
        self::assertSame(['2026-1'], $matcher->extractCandidates('Bezug 2026-1'));
        // Order preserved across alternatives.
        $multiple = $matcher->extractCandidates('Sammelüberweisung für 2026-0007 und RE-99');
        self::assertSame(['RE-99', '2026-0007'], $multiple);
    }

    public function testDeduplicatesIdenticalSamples(): void
    {
        $matcher = $this->builder->buildFromSamples(['RE-12345', 're-12345', 'RE-12345']);

        // Same shape → only one regex compiled (case-insensitive).
        self::assertCount(1, $matcher->regexes);
    }

    public function testExemplifyMatchesReturnsRange(): void
    {
        $variants = $this->builder->exemplifyMatches('RE-12345');

        self::assertContains('RE-12345', $variants);
        // Min variant: 1 fewer to 2 fewer digits, padded with '1'.
        self::assertNotEmpty(array_filter($variants, fn ($v) => preg_match('/^RE-1+$/', $v) && strlen($v) < 8));
        // Max variant: more digits, padded with '9'.
        self::assertNotEmpty(array_filter($variants, fn ($v) => str_starts_with($v, 'RE-99999')));
    }

    public function testIgnoresStraySamplePunctuation(): void
    {
        // A user typing "RE: 12345" gets the same matcher as "RE 12345".
        $matcher = $this->builder->buildFromSamples(['RE: 12345']);

        self::assertSame(['RE 99'], $matcher->extractCandidates('Bezug RE 99'));
    }
}
