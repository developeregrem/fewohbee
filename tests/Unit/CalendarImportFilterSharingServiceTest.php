<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\CalendarSyncImport;
use App\Entity\ReservationOrigin;
use App\Repository\CalendarSyncImportRepository;
use App\Service\Calendar\Sync\CalendarImportFilterSharingService;
use PHPUnit\Framework\TestCase;

/** Verify that portal-label filters are shared only between matching calendar hosts. */
final class CalendarImportFilterSharingServiceTest extends TestCase
{
    public function testNewRoomReusesConfiguredFiltersFromSamePortalHost(): void
    {
        $configuredImport = $this->createImport('https://www.airbnb.com/calendar/ical/room-one.ics?key=secret');
        $configuredImport
            ->setExcludedSummaries(['not available'])
            ->setExcludedSummaryTerms(['blocked']);
        $newImport = $this->createImport('https://airbnb.com/calendar/ical/room-two.ics');
        $repository = $this->createMock(CalendarSyncImportRepository::class);
        $repository
            ->expects(self::once())
            ->method('findSummaryFilterSharingImports')
            ->with($newImport)
            ->willReturn([$configuredImport]);

        (new CalendarImportFilterSharingService($repository))->reuseForNewImport($newImport);

        self::assertSame(['not available'], $newImport->getExcludedSummaries());
        self::assertSame(['blocked'], $newImport->getExcludedSummaryTerms());
    }

    public function testNewlyChosenFiltersAreOnlySharedWithMatchingPortalHosts(): void
    {
        $newImport = $this->createImport('https://airbnb.com/calendar/ical/new.ics');
        $newImport->setExcludedSummaryTerms(['Not available']);
        $matchingImport = $this->createImport('https://www.airbnb.com/calendar/ical/existing.ics');
        $otherPortalImport = $this->createImport('https://ical.booking.com/v1/export?t=secret');
        $otherPortalImport->setExcludedSummaryTerms(['keep me']);
        $repository = $this->createStub(CalendarSyncImportRepository::class);
        $repository
            ->method('findSummaryFilterSharingImports')
            ->willReturn([$matchingImport, $otherPortalImport]);

        (new CalendarImportFilterSharingService($repository))->reuseForNewImport($newImport);

        self::assertSame(['Not available'], $matchingImport->getExcludedSummaryTerms());
        self::assertSame(['keep me'], $otherPortalImport->getExcludedSummaryTerms());
    }

    public function testEditedFiltersIncludingEmptySelectionAreShared(): void
    {
        $editedImport = $this->createImport('https://ical.booking.com/v1/edited');
        $otherImport = $this->createImport('https://ical.booking.com/v1/other');
        $otherImport
            ->setExcludedSummaries(['old exact filter'])
            ->setExcludedSummaryTerms(['old partial filter']);
        $repository = $this->createStub(CalendarSyncImportRepository::class);
        $repository
            ->method('findSummaryFilterSharingImports')
            ->willReturn([$otherImport]);

        (new CalendarImportFilterSharingService($repository))->shareFromExistingImport($editedImport);

        self::assertSame([], $otherImport->getExcludedSummaries());
        self::assertSame([], $otherImport->getExcludedSummaryTerms());
    }

    public function testPreviewReceivesPreviouslyConfiguredFilters(): void
    {
        $otherPortalImport = $this->createImport('https://ical.booking.com/v1/calendar');
        $otherPortalImport->setExcludedSummaryTerms(['unrelated']);
        $emptyImport = $this->createImport('https://airbnb.com/calendar/ical/empty.ics');
        $configuredImport = $this->createImport('https://www.airbnb.com/calendar/ical/configured.ics');
        $configuredImport
            ->setExcludedSummaries(['not available'])
            ->setExcludedSummaryTerms(['blocked']);
        $repository = $this->createMock(CalendarSyncImportRepository::class);
        $repository
            ->expects(self::once())
            ->method('findSummaryFilterSharingImports')
            ->willReturn([$otherPortalImport, $emptyImport, $configuredImport]);

        $filterSet = (new CalendarImportFilterSharingService($repository))
            ->findReusableFilterSet('https://AIRBNB.COM./calendar/ical/preview.ics');

        self::assertNotNull($filterSet);
        self::assertSame([
            'exact' => ['not available'],
            'terms' => ['blocked'],
        ], $filterSet->toArray());
    }

    public function testSameReservationOriginDoesNotShareAcrossDifferentPortalHosts(): void
    {
        $origin = $this->createOrigin();
        $airbnbImport = $this->createImport('https://airbnb.com/calendar/ical/room.ics');
        $airbnbImport
            ->setReservationOrigin($origin)
            ->setExcludedSummaryTerms(['Not available']);
        $bookingImport = $this->createImport('https://ical.booking.com/v1/calendar');
        $bookingImport
            ->setReservationOrigin($origin)
            ->setExcludedSummaryTerms(['keep me']);
        $repository = $this->createStub(CalendarSyncImportRepository::class);
        $repository
            ->method('findSummaryFilterSharingImports')
            ->willReturn([$bookingImport]);

        (new CalendarImportFilterSharingService($repository))->shareFromExistingImport($airbnbImport);

        self::assertSame(['keep me'], $bookingImport->getExcludedSummaryTerms());
    }

    public function testInvalidCalendarUrlDoesNotLookUpReusableFilters(): void
    {
        $repository = $this->createMock(CalendarSyncImportRepository::class);
        $repository
            ->expects(self::never())
            ->method('findSummaryFilterSharingImports');

        $filterSet = (new CalendarImportFilterSharingService($repository))
            ->findReusableFilterSet('not a calendar URL');

        self::assertNull($filterSet);
    }

    public function testDisabledSharingLeavesOtherRoomsUntouched(): void
    {
        $import = $this->createImport('https://airbnb.com/calendar/ical/room.ics');
        $import->setShareSummaryFilters(false);
        $repository = $this->createMock(CalendarSyncImportRepository::class);
        $repository
            ->expects(self::never())
            ->method('findSummaryFilterSharingImports');
        $service = new CalendarImportFilterSharingService($repository);

        $service->reuseForNewImport($import);
        $service->shareFromExistingImport($import);
    }

    /** Create a shared business origin to prove that it does not identify the calendar portal. */
    private function createOrigin(): ReservationOrigin
    {
        $origin = new ReservationOrigin();
        $origin->setName('Airbnb');

        return $origin;
    }

    /** Create a minimally initialized import for filter-sharing tests. */
    private function createImport(string $url): CalendarSyncImport
    {
        $import = new CalendarSyncImport();
        $import->setUrl($url);

        return $import;
    }
}
