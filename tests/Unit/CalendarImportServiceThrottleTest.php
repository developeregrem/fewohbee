<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\CalendarSyncImport;
use App\Repository\ReservationRepository;
use App\Repository\RoomBlockRepository;
use App\Service\CalendarImportService;
use App\Service\Ics\IcsEventParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Verify calendar import throttling for fallback and forced cron executions. */
final class CalendarImportServiceThrottleTest extends TestCase
{
    public function testRegularSyncWaitsForFullThrottleWindow(): void
    {
        $clock = new MockClock();
        $entityManager = $this->createEntityManagerExpectingSyncRuns(2);
        $service = $this->createService($entityManager, new ArrayAdapter(clock: $clock));

        $service->syncActiveImports();
        $clock->sleep(CalendarImportService::SYNC_THROTTLE_SECONDS - 1);
        $service->syncActiveImports();
        // CacheItem::expiresAfter() uses the real microtime, so cross the boundary by one second.
        $clock->sleep(2);
        $service->syncActiveImports();
    }

    public function testForcedSyncRunsDespiteExistingThrottleMarker(): void
    {
        $entityManager = $this->createEntityManagerExpectingSyncRuns(2);
        $service = $this->createService($entityManager, new ArrayAdapter());

        $service->syncActiveImports();
        $service->syncActiveImports(true);
    }

    public function testForcedSyncCreatesThrottleMarkerForFallback(): void
    {
        $clock = new MockClock();
        $entityManager = $this->createEntityManagerExpectingSyncRuns(2);
        $service = $this->createService($entityManager, new ArrayAdapter(clock: $clock));

        $service->syncActiveImports(true);
        $clock->sleep(CalendarImportService::SYNC_THROTTLE_SECONDS - 1);
        $service->syncActiveImports();
        // CacheItem::expiresAfter() uses the real microtime, so cross the boundary by one second.
        $clock->sleep(2);
        $service->syncActiveImports();
    }

    /** Create an entity manager whose repository lookup represents a full sync run. */
    private function createEntityManagerExpectingSyncRuns(int $expectedRuns): EntityManagerInterface&MockObject
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::exactly($expectedRuns))
            ->method('getRepository')
            ->with(CalendarSyncImport::class)
            ->willReturn($repository);

        return $entityManager;
    }

    /** Build the service with an isolated in-memory throttle cache. */
    private function createService(EntityManagerInterface $entityManager, ArrayAdapter $cache): CalendarImportService
    {
        return new CalendarImportService(
            $entityManager,
            $this->createStub(HttpClientInterface::class),
            $cache,
            $this->createStub(TranslatorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ReservationRepository::class),
            $this->createStub(RoomBlockRepository::class),
            $this->createStub(IcsEventParser::class),
        );
    }
}
