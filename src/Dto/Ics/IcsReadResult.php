<?php

declare(strict_types=1);

namespace App\Dto\Ics;

/**
 * Result of reading one ICS feed, including source and skip counts.
 */
final readonly class IcsReadResult
{
    /**
     * @param list<IcsOccurrence> $occurrences     parsed occurrences
     * @param int                 $skipped         VEVENTs that could not be evaluated
     * @param int                 $sourceEventCount number of VEVENT components before filtering
     */
    public function __construct(
        public array $occurrences,
        public int $skipped,
        public int $sourceEventCount,
    ) {
    }
}
