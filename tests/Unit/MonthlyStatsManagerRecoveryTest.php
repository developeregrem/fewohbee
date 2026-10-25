<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\MonthlyStatsSnapshot;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Entity\Subsidiary;
use App\Repository\AppartmentRepository;
use App\Repository\MonthlyStatsSnapshotRepository;
use App\Repository\PostalCodeDataRepository;
use App\Repository\ReservationRepository;
use App\Repository\ReservationStatusRepository;
use App\Service\AvailabilityService;
use App\Service\InvoiceService;
use App\Service\MonthlyStatsService;
use App\Service\StatisticsService;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MonthlyStatsManagerRecoveryTest extends TestCase
{
    public function testOpenManagerUsesTheCachedSnapshotWithoutResetting(): void
    {
        $subsidiary = (new Subsidiary())->setId(42);
        $snapshot = $this->cachedSnapshot();
        $repo = $this->createMock(MonthlyStatsSnapshotRepository::class);
        $repo->expects(self::once())->method('findOneByMonthYearSubsidiary')
            ->with(3, 2026, $subsidiary)->willReturn($snapshot);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->expects(self::once())->method('getRepository')->with(MonthlyStatsSnapshot::class)->willReturn($repo);
        $manager->expects(self::never())->method('flush');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('resetManager');
        $registry->expects(self::never())->method('getManager');

        $payload = $this->service($manager, $registry)->getOrCreateSnapshotWithWarnings(3, 2026, $subsidiary);

        self::assertSame($snapshot, $payload['snapshot']);
        self::assertSame($snapshot->getMetrics()['warnings'], $payload['warnings']);
    }

    public function testClosedManagerUsesTheReplacementForTheSnapshotLookup(): void
    {
        $subsidiary = (new Subsidiary())->setId(42);
        $snapshot = $this->cachedSnapshot();
        $repo = $this->createMock(MonthlyStatsSnapshotRepository::class);
        $repo->expects(self::once())->method('findOneByMonthYearSubsidiary')
            ->with(3, 2026, $subsidiary)->willReturn($snapshot);
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $closed->expects(self::never())->method('getRepository');
        $replacement = $this->createMock(EntityManagerInterface::class);
        $replacement->method('isOpen')->willReturn(true);
        $replacement->expects(self::once())->method('getRepository')->with(MonthlyStatsSnapshot::class)->willReturn($repo);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('resetManager');
        $registry->expects(self::once())->method('getManager')->willReturn($replacement);

        $payload = $this->service($closed, $registry)->getOrCreateSnapshotWithWarnings(3, 2026, $subsidiary);

        self::assertSame($snapshot, $payload['snapshot']);
    }

    public function testReplacementMustBeAnOrmEntityManager(): void
    {
        $closed = $this->createStub(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('resetManager');
        $registry->expects(self::once())->method('getManager')->willReturn($this->createStub(ObjectManager::class));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Monthly statistics require an ORM entity manager.');

        $this->service($closed, $registry)->getOrCreateSnapshotWithWarnings(3, 2026, new Subsidiary());
    }

    #[DataProvider('concurrentSnapshotCases')]
    public function testConcurrentSnapshotCreationUsesTheRecoveredManager(bool $force, bool $recoverable): void
    {
        $subsidiary = (new Subsidiary())->setId(42);
        $existing = $this->cachedSnapshot();
        $storedMetrics = $existing->getMetrics();
        $snapshotRepo = $this->createStub(MonthlyStatsSnapshotRepository::class);
        $snapshotRepo->method('findOneByMonthYearSubsidiary')->willReturn(null);
        $appartments = $this->createStub(AppartmentRepository::class);
        $appartments->method('loadSumBedsMinForObject')->willReturn(10);
        $appartments->method('loadRoomCountForObject')->willReturn(5);
        $reservations = $this->createStub(ReservationRepository::class);
        $reservations->method('loadReservationsForMonth')->willReturn([]);
        $reservations->method('loadUtilizationForDay')->willReturn(0);
        $statuses = $this->createStub(ReservationStatusRepository::class);
        $statuses->method('findDefaultIds')->willReturn([1]);
        $duplicate = $this->duplicateException();
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->method('getRepository')->willReturnMap([
            [MonthlyStatsSnapshot::class, $snapshotRepo],
            [Appartment::class, $appartments],
            [Reservation::class, $reservations],
            [ReservationStatus::class, $statuses],
        ]);
        $manager->expects(self::once())->method('getReference')->with(Subsidiary::class, 42)->willReturn($subsidiary);
        $manager->expects(self::once())->method('persist')->with(self::isInstanceOf(MonthlyStatsSnapshot::class));
        $manager->expects(self::once())->method('flush')->willThrowException($duplicate);
        $recoveredRepo = $this->createMock(MonthlyStatsSnapshotRepository::class);
        $recoveredRepo->expects(self::once())->method('findOneByMonthYearSubsidiary')
            ->with(3, 2026, $subsidiary)->willReturn($recoverable ? $existing : null);
        $replacement = $this->createMock(EntityManagerInterface::class);
        $replacement->expects(self::once())->method('getRepository')
            ->with(MonthlyStatsSnapshot::class)->willReturn($recoveredRepo);
        $replacement->expects($force && $recoverable ? self::once() : self::never())->method('flush');
        $replacement->expects(self::never())->method('persist');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('resetManager');
        $registry->expects(self::once())->method('getManager')->willReturn($replacement);
        if (!$recoverable) {
            $this->expectExceptionObject($duplicate);
        }

        $payload = $this->service($manager, $registry)->getOrCreateSnapshotWithWarnings(3, 2026, $subsidiary, $force);

        self::assertSame($existing, $payload['snapshot']);
        if ($force) {
            self::assertSame(10, $existing->getMetrics()['inventory']['beds_total']);
            self::assertSame(['year' => 2026, 'month' => 3], $existing->getMetrics()['period']);
            self::assertSame([], $payload['warnings']);
        } else {
            self::assertSame($storedMetrics, $existing->getMetrics());
            self::assertSame($storedMetrics['warnings'], $payload['warnings']);
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function concurrentSnapshotCases(): iterable
    {
        yield 'reuse the concurrent snapshot' => [false, true];
        yield 'force updating the concurrent snapshot' => [true, true];
        yield 'preserve the original failure when no snapshot exists' => [false, false];
    }

    private function service(EntityManagerInterface $manager, ManagerRegistry $registry): MonthlyStatsService
    {
        return new MonthlyStatsService(
            $manager,
            $registry,
            $this->createStub(StatisticsService::class),
            $this->createStub(InvoiceService::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(PostalCodeDataRepository::class),
            $this->createStub(AvailabilityService::class),
        );
    }

    private function cachedSnapshot(): MonthlyStatsSnapshot
    {
        $snapshot = new MonthlyStatsSnapshot();
        $snapshot->setMetrics([
            'legacy_value' => 'preserved',
            'warnings' => [['reservation_id' => 91, 'ignored' => true]],
        ]);

        return $snapshot;
    }

    private function duplicateException(): UniqueConstraintViolationException
    {
        $driver = new class('Concurrent snapshot') extends \RuntimeException implements DriverException {
            public function getSQLState(): string
            {
                return '23000';
            }
        };

        return new UniqueConstraintViolationException($driver, null);
    }
}
