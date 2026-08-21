<?php

declare(strict_types=1);

namespace App\Service;

final readonly class CalendarEntrySyncResult
{
    /**
     * @param int $skippedInvalid VEVENTs that could not be used - a DTEND not
     *                            after DTSTART, a span beyond
     *                            IcsEventSpanResolver::MAX_EVENT_SPAN_DAYS, or
     *                            a recurrence rule the reader could not
     *                            evaluate. Counted rather than swallowed: a
     *                            discarded event the user is never told about
     *                            looks like the calendar lost it.
     */
    public function __construct(
        public int $new,
        public int $updated,
        public int $unchanged,
        public int $skippedInvalid = 0,
    ) {
    }

    public function total(): int
    {
        return $this->new + $this->updated + $this->unchanged;
    }
}
