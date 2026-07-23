<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ReservationTable\DayCalendarEntry;
use App\Dto\ReservationTable\DayColumn;
use App\Dto\ReservationTable\DayDecoration;
use App\Dto\ReservationTable\TableCell;
use App\Dto\ReservationTable\TableGrid;
use App\Dto\ReservationTable\TableHeader;
use App\Dto\ReservationTable\TableRow;
use App\Entity\Appartment;
use App\Entity\Reservation;
use App\Entity\RoomBlock;

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
     * @param RoomBlock[]   $allBlocks all room blocks for ALL apartments in the period (pre-loaded)
     * @param array<string, DayDecoration> $decorations holidays/calendar entries per Y-m-d, from
     *                                                  ReservationTableDecorationService; empty leaves the
     *                                                  day columns bare
     */
    public function buildGrid(
        array $apartments,
        \DateTimeImmutable $startDate,
        int $interval,
        array $allReservations,
        bool $showSubsidiaryHeaders = false,
        array $allBlocks = [],
        array $decorations = [],
    ): TableGrid {
        $days = $this->buildDays($startDate, $interval);
        $monthHeaders = $this->buildMonthHeaders($days);
        $weekHeaders = $this->buildWeekHeaders($days);
        $dayColumns = $this->buildDayColumns($days, $decorations);

        // Group reservations by apartment ID
        $reservationsByApartment = [];
        foreach ($allReservations as $reservation) {
            $aptId = $reservation->getAppartment()->getId();
            $reservationsByApartment[$aptId][] = $reservation;
        }

        // Group room blocks by apartment ID
        $blocksByApartment = [];
        foreach ($allBlocks as $block) {
            $blocksByApartment[$block->getAppartment()->getId()][] = $block;
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
            // blocks act on the physical room -> attach them to the first row only
            $aptBlocks = $blocksByApartment[$apartment->getId()] ?? [];

            if ($apartment->isMultipleOccupancy() && count($aptReservations) > 0) {
                $occupancyRows = $this->resolveMultipleOccupancy($aptReservations);
                $first = true;
                foreach ($occupancyRows as $rowReservations) {
                    $cells = $this->buildCellsForRow($days, $rowReservations, $startDate, $interval, $first ? $aptBlocks : []);
                    $rows[] = new TableRow($apartment, $cells, !$first);
                    $first = false;
                }
            } else {
                $cells = $this->buildCellsForRow($days, $aptReservations, $startDate, $interval, $aptBlocks);
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
     * @param \DateTimeImmutable[]         $days
     * @param array<string, DayDecoration> $decorations keyed by Y-m-d
     *
     * @return DayColumn[]
     */
    public function buildDayColumns(array $days, array $decorations = []): array
    {
        $columns = [];
        foreach ($days as $day) {
            $dow = (int) $day->format('N');
            $dateKey = $day->format('Y-m-d');
            $decoration = $decorations[$dateKey] ?? null;
            $entries = $decoration?->calendarEntries ?? [];

            $columns[] = new DayColumn(
                date: $dateKey,
                dayOfMonth: (int) $day->format('j'),
                isoDayOfWeek: $dow,
                isWeekend: $dow >= 6,
                holidays: $decoration?->holidays ?? [],
                calendarEntries: $entries,
                accentColors: array_map(static fn (DayCalendarEntry $e) => $e->color, $entries),
                newEntryUrl: $decoration?->newEntryUrl,
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
     * @param RoomBlock[]          $blocks
     *
     * @return TableCell[]
     */
    public function buildCellsForRow(array $days, array $reservations, \DateTimeImmutable $periodStart, int $interval, array $blocks = []): array
    {
        $periodEnd = $periodStart->modify('+'.$interval.' days');
        $numDays = count($days);
        $numSlots = $numDays * 2;

        // Initialize slots: null = empty
        $slots = array_fill(0, $numSlots, null);

        // Fill slots with reservations first, then blocks into the remaining free slots.
        // Both use the same half-day rules (start day: right half, exclusive end day: left half),
        // so a block starting on a departure day shares the day cell with the reservation.
        foreach (array_merge($reservations, $blocks) as $entry) {
            $entryStartStr = $entry->getStartDate()->format('Y-m-d');
            $entryEndStr = $entry->getEndDate()->format('Y-m-d');

            foreach ($days as $dayIndex => $day) {
                $dayStr = $day->format('Y-m-d');

                // Does this entry cover this day? (string comparison = timezone-safe)
                if ($entryStartStr > $dayStr || $entryEndStr < $dayStr) {
                    continue;
                }

                $leftSlot = $dayIndex * 2;
                $rightSlot = $dayIndex * 2 + 1;

                $isEntryStartDay = ($entryStartStr === $dayStr);
                $isEntryEndDay = ($entryEndStr === $dayStr);

                $fillLeft = true;
                $fillRight = true;

                if ($isEntryStartDay && !$isEntryEndDay) {
                    // Arrival day (multi-day entry): only right half
                    $fillLeft = false;
                } elseif ($isEntryEndDay && !$isEntryStartDay) {
                    // Departure day: only left half
                    $fillRight = false;
                }
                // Single-day (both start and end): fill both
                // Middle day (neither): fill both

                if ($fillLeft && $slots[$leftSlot] === null) {
                    $slots[$leftSlot] = $entry;
                }
                if ($fillRight && $slots[$rightSlot] === null) {
                    $slots[$rightSlot] = $entry;
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
                // Occupied cell — merge consecutive slots with the same entry
                $entry = $slots[$i];
                $startSlot = $i;
                while ($i < $numSlots && $slots[$i] === $entry) {
                    ++$i;
                }
                $span = $i - $startSlot;

                $position = $this->determinePositionFromDates(
                    $entry->getStartDate()->format('Y-m-d'),
                    $entry->getEndDate()->format('Y-m-d'),
                    $periodStart,
                    $periodEnd
                );

                if ($entry instanceof RoomBlock) {
                    $cells[] = new TableCell(
                        date: $dayStr,
                        type: TableCell::TYPE_BLOCKED,
                        span: $span,
                        position: $position,
                        displayName: $entry->getReason(),
                        blockId: $entry->getId(),
                        startsAtDayBoundary: $isLeft,
                    );
                } else {
                    $cells[] = new TableCell(
                        date: $dayStr,
                        type: TableCell::TYPE_RESERVATION,
                        span: $span,
                        position: $position,
                        reservation: $entry,
                        displayName: $this->getDisplayName($entry),
                        color: $entry->getReservationStatus()?->getColor(),
                        contrastColor: $entry->getReservationStatus()?->getContrastColor(),
                        reservationId: $entry->getId(),
                        startsAtDayBoundary: $isLeft,
                    );
                }
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
    private function determinePositionFromDates(string $resStartStr, string $resEndStr, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): string
    {
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
