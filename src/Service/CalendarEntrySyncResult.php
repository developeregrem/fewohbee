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
     */
    public function __construct(
        public int $new,
        public int $updated,
        public int $unchanged,
        public int $skippedRecurring = 0,
    ) {
    }

    public function total(): int
    {
        return $this->new + $this->updated + $this->unchanged;
    }
}
