<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\CalendarSyncImportRepository;
use App\Repository\GuestCategoryRepository;
use App\Repository\ReservationRepository;
use App\Service\AvailabilityService;
use App\Service\Calendar\Sync\CalendarImportSummaryMatcher;
use App\Service\Calendar\Sync\Ics\IcsFeedClient;
use App\Service\Calendar\Sync\Ics\IcsOccurrenceReader;
use App\Service\Calendar\Sync\ImportedReservationSynchronizer;
use App\Service\Calendar\Sync\ReservationCalendarImportService;
use App\Service\ReservationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Verify calendar import throttling for fallback and forced cron executions. */
final class ReservationCalendarImportServiceThrottleTest extends TestCase
{
    public function testRegularSyncWaitsForFullThrottleWindow(): void
    {
        $clock = new MockClock();
        $service = $this->createService(
            $this->createRepositoryExpectingSyncRuns(2),
            new ArrayAdapter(clock: $clock),
        );

        $service->syncActiveImports();
        $clock->sleep(ReservationCalendarImportService::SYNC_THROTTLE_SECONDS - 1);
        $service->syncActiveImports();
        $clock->sleep(2);
        $service->syncActiveImports();
    }

    public function testForcedSyncRunsDespiteExistingThrottleMarker(): void
    {
        $service = $this->createService(
            $this->createRepositoryExpectingSyncRuns(2),
            new ArrayAdapter(),
        );

        $service->syncActiveImports();
        $service->syncActiveImports(true);
    }

    public function testForcedSyncCreatesThrottleMarkerForFallback(): void
    {
        $clock = new MockClock();
        $service = $this->createService(
            $this->createRepositoryExpectingSyncRuns(2),
            new ArrayAdapter(clock: $clock),
        );

        $service->syncActiveImports(true);
        $clock->sleep(ReservationCalendarImportService::SYNC_THROTTLE_SECONDS - 1);
        $service->syncActiveImports();
        $clock->sleep(2);
        $service->syncActiveImports();
    }

    /** Create a repository whose query count represents full synchronization runs. */
    private function createRepositoryExpectingSyncRuns(int $expectedRuns): CalendarSyncImportRepository&MockObject
    {
        $repository = $this->createMock(CalendarSyncImportRepository::class);
        $repository
            ->expects(self::exactly($expectedRuns))
            ->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([]);

        return $repository;
    }

    /** Build the orchestrator with an isolated in-memory throttle cache. */
    private function createService(
        CalendarSyncImportRepository $repository,
        ArrayAdapter $cache,
    ): ReservationCalendarImportService {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $reservationSynchronizer = new ImportedReservationSynchronizer(
            $entityManager,
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ReservationRepository::class),
            $this->createStub(AvailabilityService::class),
            $this->createStub(GuestCategoryRepository::class),
            $this->createStub(ReservationService::class),
        );

        return new ReservationCalendarImportService(
            $entityManager,
            $repository,
            new IcsFeedClient($this->createStub(HttpClientInterface::class)),
            $cache,
            $this->createStub(TranslatorInterface::class),
            new IcsOccurrenceReader(),
            $reservationSynchronizer,
            new CalendarImportSummaryMatcher(),
        );
    }
}
