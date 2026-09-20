<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\InvalidReservationPeriodException;
use App\Service\ReservationPeriodService;
use PHPUnit\Framework\TestCase;

/**
 * Covers the shared reservation-period invariants used before availability and pricing work.
 */
final class ReservationPeriodServiceTest extends TestCase
{
    public function testParsesValidPeriod(): void
    {
        $period = (new ReservationPeriodService())->parse('2026-09-12', '2026-09-13');

        self::assertSame('2026-09-12', $period->start->format('Y-m-d'));
        self::assertSame('2026-09-13', $period->end->format('Y-m-d'));
        self::assertSame(1, $period->nights);
    }

    public function testRetainsOneNightForSameDayPeriod(): void
    {
        $period = (new ReservationPeriodService())->parse('2026-09-12', '2026-09-12');

        self::assertSame(1, $period->nights);
    }

    public function testRejectsMalformedDate(): void
    {
        $this->expectException(InvalidReservationPeriodException::class);
        $this->expectExceptionMessage('reservation.period.invalid_dates');

        (new ReservationPeriodService())->parse('2026-02-30', '2026-03-02');
    }

    public function testRejectsDepartureBeforeArrival(): void
    {
        $this->expectException(InvalidReservationPeriodException::class);
        $this->expectExceptionMessage('reservation.period.end_before_start');

        (new ReservationPeriodService())->parse('2026-09-13', '2026-09-12');
    }

    public function testAcceptsConfiguredMaximumPeriod(): void
    {
        $start = new \DateTimeImmutable('2026-09-12');
        $end = $start->modify(sprintf('+%d days', ReservationPeriodService::MAX_NIGHTS));

        $period = (new ReservationPeriodService())->validate($start, $end);

        self::assertSame(ReservationPeriodService::MAX_NIGHTS, $period->nights);
    }

    public function testRejectsReproducedTwoThousandYearPeriod(): void
    {
        $this->expectException(InvalidReservationPeriodException::class);
        $this->expectExceptionMessage('reservation.period.too_long');

        (new ReservationPeriodService())->parse('2026-09-12', '4026-09-13');
    }
}
