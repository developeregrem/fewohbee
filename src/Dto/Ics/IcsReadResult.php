<?php

declare(strict_types=1);

namespace App\Dto\Ics;

/**
 * What one pass over an ICS feed yielded: the occurrences inside the caller's
 * window, and how many events had to be dropped along the way.
 *
 * The count travels with the occurrences so the sync can tell the user that a
 * feed was read but parts of it were unusable, instead of reporting a clean
 * run over a feed that half failed.
 */
final readonly class IcsReadResult
{
    /**
     * @param list<IcsOccurrence> $occurrences ascending per event, not globally sorted
     * @param int                 $skipped     VEVENTs whose recurrence could not be evaluated
     */
    public function __construct(
        public array $occurrences,
        public int $skipped,
    ) {
    }
}
