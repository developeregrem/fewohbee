<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\CalendarSyncImport;
use App\Service\Calendar\Sync\CalendarImportSummaryMatcher;
use PHPUnit\Framework\TestCase;

/** Verify user-confirmed portal-label matching without hardcoded provider assumptions. */
final class CalendarImportSummaryMatcherTest extends TestCase
{
    public function testExactFilterIgnoresCasingAndWhitespaceOnly(): void
    {
        $import = new CalendarSyncImport();
        $import->setExcludedSummaries(['  Not   available  ']);
        $matcher = new CalendarImportSummaryMatcher();

        self::assertTrue($matcher->isExcluded($import, 'not available'));
        self::assertTrue($matcher->isExcluded($import, " NOT\tAVAILABLE "));
        self::assertFalse($matcher->isExcluded($import, 'Airbnb (Not available)'));
    }

    public function testPartialFilterCoversPortalLabelVariations(): void
    {
        $import = new CalendarSyncImport();
        $import->setExcludedSummaryTerms(['Not available']);
        $matcher = new CalendarImportSummaryMatcher();

        self::assertTrue($matcher->isExcluded($import, 'Not available'));
        self::assertTrue($matcher->isExcluded($import, 'Airbnb (Not available)'));
        self::assertFalse($matcher->isExcluded($import, 'Reserved'));
    }

    public function testBookingDotComLabelIsIncludedWithoutUserFilter(): void
    {
        $import = new CalendarSyncImport();
        $matcher = new CalendarImportSummaryMatcher();

        self::assertFalse($matcher->isExcluded($import, 'CLOSED - Not available'));
    }

    public function testEmptySummaryCanBeExcludedExplicitly(): void
    {
        $import = new CalendarSyncImport();
        $import->setExcludedSummaries([CalendarImportSummaryMatcher::EMPTY_SUMMARY_FILTER]);
        $matcher = new CalendarImportSummaryMatcher();

        self::assertTrue($matcher->isExcluded($import, ''));
        self::assertTrue($matcher->isExcluded($import, "  \n "));
    }

    public function testEntityDeduplicatesAndBoundsUntrustedFilterValues(): void
    {
        $import = new CalendarSyncImport();
        $import->setExcludedSummaryTerms(array_fill(0, 60, str_repeat('x', 300)));

        self::assertCount(1, $import->getExcludedSummaryTerms());
        self::assertSame(CalendarSyncImport::MAX_SUMMARY_FILTER_LENGTH, mb_strlen($import->getExcludedSummaryTerms()[0]));
    }
}
