<?php

declare(strict_types=1);

namespace App\Dto\ReservationTable;

/**
 * What one day column shows besides reservations. Built per period by
 * ReservationTableDecorationService and folded into DayColumn by
 * ReservationTableService.
 */
final class DayDecoration
{
    /**
     * @param string[]           $holidays
     * @param DayCalendarEntry[] $calendarEntries
     */
    public function __construct(
        public readonly array $holidays = [],
        public readonly array $calendarEntries = [],
        public readonly ?string $newEntryUrl = null,
    ) {
    }
}
