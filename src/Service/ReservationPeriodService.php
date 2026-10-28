<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ReservationPeriod;
use App\Entity\Appartment;
use App\Exception\InvalidReservationPeriodException;

/**
 * Parses and validates reservation periods before availability or pricing work starts.
 */
final class ReservationPeriodService
{
    /** A generous safety ceiling that prevents unbounded per-night processing. */
    public const MAX_NIGHTS = 3660;

    /**
     * Strictly parses the date-only values submitted by reservation forms.
     *
     * @throws InvalidReservationPeriodException when either date or the resulting period is invalid
     */
    public function parse(string $start, string $end): ReservationPeriod
    {
        $startDate = $this->parseDate($start);
        $endDate = $this->parseDate($end);
        if (null === $startDate || null === $endDate) {
            throw new InvalidReservationPeriodException('reservation.period.invalid_dates');
        }

        return $this->validate($startDate, $endDate);
    }

    /**
     * Reads the dates of an admin form for the given room. For an event space "to" is the last
     * day of the event and is stored as the day after, so "Saturday to Saturday" books one day.
     * For accommodation "to" stays the departure day.
     *
     * @throws InvalidReservationPeriodException when the stored period exceeds the safety ceiling
     */
    public function forRoom(ReservationPeriod $input, ?Appartment $room): ReservationPeriod
    {
        if (true !== $room?->isEventSpace()) {
            return $input;
        }

        return $this->validate($input->start, $input->end->modify('+1 day'));
    }

    /**
     * Validates an internal date range before code allocates or queries data per night.
     * Same-day reservations retain the existing one-night behaviour.
     *
     * @throws InvalidReservationPeriodException when departure precedes arrival or the safety ceiling is exceeded
     */
    public function validate(\DateTimeInterface $start, \DateTimeInterface $end): ReservationPeriod
    {
        $startDate = \DateTimeImmutable::createFromInterface($start)->setTime(0, 0);
        $endDate = \DateTimeImmutable::createFromInterface($end)->setTime(0, 0);

        if ($endDate < $startDate) {
            throw new InvalidReservationPeriodException('reservation.period.end_before_start');
        }

        $nights = max(1, (int) $startDate->diff($endDate)->days);
        if ($nights > self::MAX_NIGHTS) {
            throw new InvalidReservationPeriodException('reservation.period.too_long');
        }

        return new ReservationPeriod($startDate, $endDate, $nights);
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }
}
