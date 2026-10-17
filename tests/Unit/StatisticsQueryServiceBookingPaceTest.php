<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Repository\AppartmentRepository;
use App\Repository\ReservationRepository;
use App\Service\Api\StatisticsQueryService;
use App\Service\InvoiceService;
use App\Service\StatisticsService;
use PHPUnit\Framework\TestCase;

final class StatisticsQueryServiceBookingPaceTest extends TestCase
{
    public function testCountsOnlyNightsInsideThePeriodAndBookingsUpToTheCutOff(): void
    {
        $reservations = $this->createStub(ReservationRepository::class);
        $reservations->method('loadBlockingSpansForPeriod')->willReturnCallback(
            static function (\DateTimeInterface $start, \DateTimeInterface $end, string|int $objectId, ?int $roomCategoryId, ?\DateTimeInterface $bookedBefore = null): array {
                if ('2026' !== $start->format('Y')) {
                    // Previous year: one reservation of 3 nights, booked after the cut-off.
                    return null === $bookedBefore ? [['appartmentId' => 1, 'startDate' => '2025-12-10', 'endDate' => '2025-12-13']] : [];
                }
                // Starts before the period: only 12-01 and 12-02 count.
                $early = ['appartmentId' => 1, 'startDate' => '2026-11-29', 'endDate' => '2026-12-03'];
                $late = ['appartmentId' => 2, 'startDate' => '2026-12-05', 'endDate' => '2026-12-07'];

                return null === $bookedBefore ? [$early, $late] : [$early];
            }
        );

        $result = $this->buildService($reservations)->bookingPace(
            new \DateTimeImmutable('2026-12-01'),
            new \DateTimeImmutable('2026-12-31'),
            new \DateTimeImmutable('2026-09-29'),
            'all',
            null,
        );

        self::assertSame(2, $result['rooms']);
        self::assertSame(['roomNights' => 2, 'reservations' => 1], $result['current']['bookedByCutOff']);
        self::assertSame(['roomNights' => 4, 'reservations' => 2], $result['current']['bookedNow']);
        self::assertSame('2025-12-01', $result['previousYear']['firstNight']);
        self::assertSame('2025-12-31', $result['previousYear']['lastNight']);
        self::assertSame('2025-09-29', $result['previousYear']['cutOff']);
        self::assertSame(['roomNights' => 0, 'reservations' => 0], $result['previousYear']['bookedByCutOff']);
        self::assertSame(['roomNights' => 3, 'reservations' => 1], $result['previousYear']['bookedNow']);
    }

    public function testCutOffDayItselfStillCounts(): void
    {
        $reservations = $this->createMock(ReservationRepository::class);
        $reservations->expects(self::exactly(4))->method('loadBlockingSpansForPeriod')->willReturnCallback(
            static function (\DateTimeInterface $start, \DateTimeInterface $end, string|int $objectId, ?int $roomCategoryId, ?\DateTimeInterface $bookedBefore = null): array {
                if (null !== $bookedBefore) {
                    // Exclusive bound: the day after the cut-off, at midnight.
                    self::assertSame('00:00', $bookedBefore->format('H:i'));
                    self::assertContains($bookedBefore->format('Y-m-d'), ['2026-09-30', '2025-09-30']);
                }
                // Periods end exclusive after the last night.
                self::assertContains($end->format('Y-m-d'), ['2027-01-01', '2026-01-01']);

                return [];
            }
        );

        $this->buildService($reservations)->bookingPace(
            new \DateTimeImmutable('2026-12-01'),
            new \DateTimeImmutable('2026-12-31'),
            new \DateTimeImmutable('2026-09-29'),
            'all',
            null,
        );
    }

    private function buildService(ReservationRepository $reservations): StatisticsQueryService
    {
        $apartments = $this->createStub(AppartmentRepository::class);
        $apartments->method('findAllByProperty')->willReturn([new Appartment(), new Appartment()]);

        return new StatisticsQueryService(
            $apartments,
            $this->createStub(StatisticsService::class),
            $this->createStub(InvoiceService::class),
            $reservations,
        );
    }
}
