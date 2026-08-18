<?php

declare(strict_types=1);

namespace App\Service;

final readonly class CalendarEntrySyncResult
{
    /**
     * @param int $skippedRecurring VEVENTs carrying an RRULE, which this import
     *                              does not expand (see CalendarEntrySyncService).
     *                              Counted separately so the caller can say so
     *                              instead of leaving the user with a feed that
     *                              silently produced nothing.
     * @param int $skippedInvalid   VEVENTs whose period could not be read - no
     *                              DTSTART, a DTEND not after it, or a span
     *                              beyond IcsEventSpanResolver::MAX_EVENT_SPAN_DAYS.
     *                              Counted for the same reason: a discarded
     *                              event the user is never told about looks
     *                              like the calendar lost it.
     */
    public function __construct(
        public int $new,
        public int $updated,
        public int $unchanged,
        public int $skippedRecurring = 0,
        public int $skippedInvalid = 0,
    ) {
    }

    public function total(): int
    {
        return $this->new + $this->updated + $this->unchanged;
    }
}
