<?php

declare(strict_types=1);

namespace App\Dto\ReservationTable;

/**
 * One calendar entry as the reservation table needs it: everything the day
 * popover renders, resolved up front so the template neither queries nor
 * builds URLs.
 */
final class DayCalendarEntry
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly int $calendarId,
        public readonly string $calendarName,
        public readonly string $color,
        /** Null when the viewer may not manage entries - the popover then shows the title as plain text. */
        public readonly ?string $editUrl,
        public readonly ?string $deleteUrl,
        /**
         * True when this entry belongs to a different calendar than the one
         * before it in the same day. The popover draws its separator rule off
         * this instead of comparing neighbours while rendering.
         */
        public readonly bool $startsCalendarGroup = false,
    ) {
    }
}
