<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\MonthlyStatsSnapshot;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Entity\Subsidiary;
use App\Repository\AppartmentRepository;
use App\Repository\PostalCodeDataRepository;
use App\Repository\ReservationRepository;
use App\Repository\ReservationStatusRepository;
use App\Service\AvailabilityService;
use App\Service\InvoiceService;
use App\Service\MonthlyStatsService;
use App\Service\StatisticsService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Verifies the additive `blocked` bucket: it sums blocked room/bed days
 * without touching the utilization denominator.
 */
final class MonthlyStatsServiceBlockedTest extends TestCase
{
    public function testBlockedBucketSumsRoomAndBedDays(): void
    {
        // 2 rooms blocked on Mar 1, 1 room on Mar 2, rest of the month free
        $metrics = $this->buildMetrics([
            '2026-03-01' => ['rooms' => 2, 'beds' => 5],
            '2026-03-02' => ['rooms' => 1, 'beds' => 2],
            '2026-03-03' => ['rooms' => 0, 'beds' => 0],
        ]);

        self::assertSame(['room_days' => 3, 'bed_days' => 7], $metrics['blocked']);
    }

    public function testBlockedBucketDoesNotChangeUtilizationDenominator(): void
    {
        $withBlocks = $this->buildMetrics(['2026-03-01' => ['rooms' => 5, 'beds' => 10]]);
        $withoutBlocks = $this->buildMetrics([]);

        self::assertSame(['room_days' => 5, 'bed_days' => 10], $withBlocks['blocked']);
        self::assertSame(['room_days' => 0, 'bed_days' => 0], $withoutBlocks['blocked']);
        // utilization is reservation-based only and must be identical in both runs
        self::assertSame(
            $withoutBlocks['utilization']['month_percent'],
            $withBlocks['utilization']['month_percent']
        );
        self::assertSame(10, $withBlocks['inventory']['beds_total']);
    }

    /**
     * @param array<string, array{rooms: int, beds: int}> $blockedPerDay
     */
    private function buildMetrics(array $blockedPerDay): array
    {
        $appartmentRepo = $this->createStub(AppartmentRepository::class);
        $appartmentRepo->method('loadSumBedsMinForObject')->willReturn(10);
        $appartmentRepo->method('loadRoomCountForObject')->willReturn(5);

        $reservationRepo = $this->createStub(ReservationRepository::class);
        $reservationRepo->method('loadReservationsForMonth')->willReturn([]);
        $reservationRepo->method('loadUtilizationForDay')->willReturn(0);

        $statusRepo = $this->createStub(ReservationStatusRepository::class);
        $statusRepo->method('findDefaultIds')->willReturn([1]);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->method('getRepository')->willReturnCallback(static function (string $class) use ($appartmentRepo, $reservationRepo, $statusRepo) {
            return match ($class) {
                Appartment::class => $appartmentRepo,
                Reservation::class => $reservationRepo,
                ReservationStatus::class => $statusRepo,
                MonthlyStatsSnapshot::class => null,
                Subsidiary::class => null,
                default => null,
            };
        });

        $availabilityService = $this->createStub(AvailabilityService::class);
        $availabilityService->method('getBlockedPerDay')->willReturn($blockedPerDay);

        $service = new MonthlyStatsService(
            $em,
            $this->createStub(ManagerRegistry::class),
            $this->createStub(StatisticsService::class),
            $this->createStub(InvoiceService::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(PostalCodeDataRepository::class),
            $availabilityService,
        );

        $subsidiary = new Subsidiary();
        $subsidiary->setId(42);

        return $service->buildMetrics(3, 2026, $subsidiary)['metrics'];
    }
}
