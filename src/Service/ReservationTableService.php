<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ReservationTable\DayColumn;
use App\Dto\ReservationTable\TableCell;
use App\Dto\ReservationTable\TableGrid;
use App\Dto\ReservationTable\TableHeader;
use App\Dto\ReservationTable\TableRow;
use App\Entity\Appartment;
use App\Entity\Reservation;

class ReservationTableService
{
    /**
     * Build a complete grid model for the reservation table view.
     *
     * @param Appartment[]  $apartments
     * @param \DateTimeImmutable $startDate first day of the visible period
     * @param int           $interval number of days in the visible period
     * @param Reservation[] $allReservations all reservations for ALL apartments in the period (pre-loaded)
     * @param bool          $showSubsidiaryHeaders whether to show subsidiary group headers
     */
    public function buildGrid(
        array $apartments,
        \DateTimeImmutable $startDate,
        int $interval,
        array $allReservations,
        bool $showSubsidiaryHeaders = false,
    ): TableGrid {
        $days = $this->buildDays($startDate, $interval);
        $monthHeaders = $this->buildMonthHeaders($days);
        $weekHeaders = $this->buildWeekHeaders($days);
        $dayColumns = $this->buildDayColumns($days);

        // Group reservations by apartment ID
        $reservationsByApartment = [];
        foreach ($allReservations as $reservation) {
            $aptId = $reservation->getAppartment()->getId();
            $reservationsByApartment[$aptId][] = $reservation;
        }

        $rows = [];
        $subsidiaryBreaks = [];
        $previousSubsidiaryId = null;

        foreach ($apartments as $apartment) {
            try {
                $currentSubsidiaryId = $apartment->getObject() ? $apartment->getObject()->getId() : null;
            } catch (\Error) {
                $currentSubsidiaryId = null;
            }

            if ($showSubsidiaryHeaders && $previousSubsidiaryId !== $currentSubsidiaryId) {
                $subsidiaryBreaks[$apartment->getId()] = $apartment->getObject()?->getName();
                $previousSubsidiaryId = $currentSubsidiaryId;
            }

            $aptReservations = $reservationsByApartment[$apartment->getId()] ?? [];

            if ($apartment->isMultipleOccupancy() && count($aptReservations) > 0) {
                $occupancyRows = $this->resolveMultipleOccupancy($aptReservations);
                $first = true;
                foreach ($occupancyRows as $rowReservations) {
                    $cells = $this->buildCellsForRow($days, $rowReservations, $startDate, $interval);
                    $rows[] = new TableRow($apartment, $cells, !$first);
                    $first = false;
                }
            } else {
                $cells = $this->buildCellsForRow($days, $aptReservations, $startDate, $interval);
                $rows[] = new TableRow($apartment, $cells);
            }
        }

        return new TableGrid($monthHeaders, $weekHeaders, $dayColumns, $rows, $subsidiaryBreaks);
    }

    /**
     * Build the list of days in the period.
     *
     * @return \DateTimeImmutable[]
     */
    public function buildDays(\DateTimeImmutable $startDate, int $interval): array
    {
        $days = [];
        for ($i = 0; $i <= $interval; ++$i) {
            $days[] = $startDate->modify('+'.$i.' days');
        }

        return $days;
    }

    /**
     * @param \DateTimeImmutable[] $days
     *
     * @return TableHeader[]
     */
    public function buildMonthHeaders(array $days): array
    {
        $headers = [];
        $currentMonth = null;
        $count = 0;

        foreach ($days as $day) {
            $month = (int) $day->format('n');
            if ($currentMonth === null) {
                $currentMonth = $month;
                $count = 1;
            } elseif ($month === $currentMonth) {
                ++$count;
            } else {
                $headers[] = new TableHeader((string) $currentMonth, $count);
                $currentMonth = $month;
                $count = 1;
            }
        }
        if ($count > 0 && $currentMonth !== null) {
            $headers[] = new TableHeader((string) $currentMonth, $count);
        }

        return $headers;
    }

    /**
     * @param \DateTimeImmutable[] $days
     *
     * @return TableHeader[]
     */
    public function buildWeekHeaders(array $days): array
    {
        $headers = [];
        $currentWeek = null;
        $count = 0;

        foreach ($days as $day) {
            $week = (int) $day->format('W');
            if ($currentWeek === null) {
                $currentWeek = $week;
                $count = 1;
            } elseif ($week === $currentWeek) {
                ++$count;
            } else {
                $headers[] = new TableHeader((string) $currentWeek, $count);
                $currentWeek = $week;
                $count = 1;
            }
        }
        if ($count > 0 && $currentWeek !== null) {
            $headers[] = new TableHeader((string) $currentWeek, $count);
        }

        return $headers;
    }

    /**
     * @param \DateTimeImmutable[] $days
     *
     * @return DayColumn[]
     */
    public function buildDayColumns(array $days): array
    {
        $columns = [];
        foreach ($days as $day) {
            $dow = (int) $day->format('N');
            $columns[] = new DayColumn(
                date: $day->format('Y-m-d'),
                dayOfMonth: (int) $day->format('j'),
                isoDayOfWeek: $dow,
                isWeekend: $dow >= 6,
            );
        }

        return $columns;
    }

    /**
     * Build cells for one row using the half-day slot model.
     *
     * Each day occupies 2 slots (left + right). Reservations fill slots as follows:
     * - Arrival day: right half only
     * - Departure day: left half only
     * - Middle days / single-day: both halves
     * Consecutive slots with the same reservation are merged into one TableCell.
     *
     * All date comparisons use Y-m-d strings to avoid timezone issues.
     *
     * @param \DateTimeImmutable[] $days
     * @param Reservation[]        $reservations
     *
     * @return TableCell[]
     */
    public function buildCellsForRow(array $days, array $reservations, \DateTimeImmutable $periodStart, int $interval): array
    {
        $periodEnd = $periodStart->modify('+'.$interval.' days');
        $numDays = count($days);
        $numSlots = $numDays * 2;

        // Initialize slots: null = empty
        $slots = array_fill(0, $numSlots, null);

        // Fill slots with reservations
        foreach ($reservations as $res) {
            $resStartStr = $res->getStartDate()->format('Y-m-d');
            $resEndStr = $res->getEndDate()->format('Y-m-d');

            foreach ($days as $dayIndex => $day) {
                $dayStr = $day->format('Y-m-d');

                // Does this reservation cover this day? (string comparison = timezone-safe)
                if ($resStartStr > $dayStr || $resEndStr < $dayStr) {
                    continue;
                }

                $leftSlot = $dayIndex * 2;
                $rightSlot = $dayIndex * 2 + 1;

                $isResStartDay = ($resStartStr === $dayStr);
                $isResEndDay = ($resEndStr === $dayStr);

                $fillLeft = true;
                $fillRight = true;

                if ($isResStartDay && !$isResEndDay) {
                    // Arrival day (multi-day reservation): only right half
                    $fillLeft = false;
                } elseif ($isResEndDay && !$isResStartDay) {
                    // Departure day: only left half
                    $fillRight = false;
                }
                // Single-day (both start and end): fill both
                // Middle day (neither): fill both

                if ($fillLeft && $slots[$leftSlot] === null) {
                    $slots[$leftSlot] = $res;
                }
                if ($fillRight && $slots[$rightSlot] === null) {
                    $slots[$rightSlot] = $res;
                }
            }
        }

        // Merge consecutive slots into cells
        $cells = [];
        $i = 0;

        while ($i < $numSlots) {
            $dayIndex = intdiv($i, 2);
            $isLeft = ($i % 2 === 0);
            $dayStr = $days[$dayIndex]->format('Y-m-d');

            if ($slots[$i] === null) {
                // Empty half-day cell
                $cells[] = new TableCell(
                    date: $dayStr,
                    side: $isLeft ? TableCell::SIDE_LEFT : TableCell::SIDE_RIGHT,
                    startsAtDayBoundary: $isLeft,
                );
                ++$i;
            } else {
                // Reservation cell — merge consecutive slots with same reservation
                $res = $slots[$i];
                $startSlot = $i;
                while ($i < $numSlots && $slots[$i] === $res) {
                    ++$i;
                }
                $span = $i - $startSlot;

                $position = $this->determinePosition($res, $periodStart, $periodEnd);

                $cells[] = new TableCell(
                    date: $dayStr,
                    type: TableCell::TYPE_RESERVATION,
                    span: $span,
                    position: $position,
                    reservation: $res,
                    displayName: $this->getDisplayName($res),
                    color: $res->getReservationStatus()?->getColor(),
                    contrastColor: $res->getReservationStatus()?->getContrastColor(),
                    reservationId: $res->getId(),
                    startsAtDayBoundary: $isLeft,
                );
            }
        }

        return $cells;
    }

    /**
     * Determine the visual position of a reservation relative to the visible period.
     *
     * Returns POS_FULL when fully visible, POS_START/POS_END when clipped on one side,
     * POS_MIDDLE when clipped on both sides, or POS_SINGLE for single-day reservations.
     */
    private function determinePosition(Reservation $res, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): string
    {
        $resStartStr = $res->getStartDate()->format('Y-m-d');
        $resEndStr = $res->getEndDate()->format('Y-m-d');
        $periodStartStr = $periodStart->format('Y-m-d');
        $periodEndStr = $periodEnd->format('Y-m-d');

        $startsBeforePeriod = $resStartStr < $periodStartStr;
        $endsAfterPeriod = $resEndStr > $periodEndStr;
        $isSingleDay = $resStartStr === $resEndStr;

        if ($isSingleDay) {
            return TableCell::POS_SINGLE;
        }
        if ($startsBeforePeriod && $endsAfterPeriod) {
            return TableCell::POS_MIDDLE;
        }
        if ($startsBeforePeriod) {
            return TableCell::POS_END;
        }
        if ($endsAfterPeriod) {
            return TableCell::POS_START;
        }

        return TableCell::POS_FULL;
    }

    /**
     * Resolve overlapping reservations into separate rows for multipleOccupancy apartments.
     *
     * @param Reservation[] $reservations
     *
     * @return array<int, Reservation[]> each sub-array is a non-overlapping set
     */
    public function resolveMultipleOccupancy(array $reservations): array
    {
        // Sort by start date
        usort($reservations, function (Reservation $a, Reservation $b) {
            return $a->getStartDate() <=> $b->getStartDate();
        });

        /** @var array<int, array{reservations: Reservation[], endDate: \DateTimeImmutable}> $rows */
        $rows = [];

        foreach ($reservations as $reservation) {
            $resStart = new \DateTimeImmutable($reservation->getStartDate()->format('Y-m-d'));
            $resEnd = new \DateTimeImmutable($reservation->getEndDate()->format('Y-m-d'));

            // Try to fit into an existing row
            $placed = false;
            foreach ($rows as &$row) {
                // A reservation can follow in the same row if it starts on or after the last end date
                if ($resStart >= $row['endDate']) {
                    $row['reservations'][] = $reservation;
                    $row['endDate'] = $resEnd;
                    $placed = true;
                    break;
                }
            }
            unset($row);

            if (!$placed) {
                $rows[] = [
                    'reservations' => [$reservation],
                    'endDate' => $resEnd,
                ];
            }
        }

        return array_map(fn ($row) => $row['reservations'], $rows);
    }

    /**
     * Derive the display name for a reservation cell.
     */
    public function getDisplayName(Reservation $reservation): string
    {
        if ($reservation->getBooker() !== null) {
            $booker = $reservation->getBooker();

            // Check for business company name
            foreach ($booker->getCustomerAddresses() as $address) {
                if ($address->getType() === 'CUSTOMER_ADDRESS_TYPE_BUSINESS' && !empty($address->getCompany())) {
                    return $address->getCompany();
                }
            }

            $name = $booker->getLastname();
            if (!empty($booker->getFirstname())) {
                $name .= ', '.$booker->getFirstname();
            }

            return $name;
        }

        if ($reservation->getCalendarSyncImport() !== null) {
            return $reservation->getCalendarSyncImport()->getName();
        }

        return '-';
    }
}
