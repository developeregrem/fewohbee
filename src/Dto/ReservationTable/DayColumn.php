<?php

declare(strict_types=1);

namespace App\Dto\ReservationTable;

final class DayColumn
{
    /**
     * @param string[]           $holidays        names of the public holidays falling on this day
     * @param DayCalendarEntry[] $calendarEntries entries shown in the day popover, grouped by calendar
     * @param string[]           $accentColors    one color per entry, in the order the accent bars paint them
     * @param string|null        $newEntryUrl     link to add an entry on this day, null when calendar entries are off
     */
    public function __construct(
        public readonly string $date,
        public readonly int $dayOfMonth,
        public readonly int $isoDayOfWeek,
        public readonly bool $isWeekend,
        public readonly array $holidays = [],
        public readonly array $calendarEntries = [],
        public readonly array $accentColors = [],
        public readonly ?string $newEntryUrl = null,
    ) {
    }
}
