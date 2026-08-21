<?php

declare(strict_types=1);

namespace App\Dto\Ics;

/**
 * One occurrence of a VEVENT, already resolved against the calendar's time
 * zone and with any RRULE expanded into a separate instance.
 *
 * Produced by IcsOccurrenceReader and consumed by IcsEventSpanResolver. It
 * exists so the day arithmetic downstream sees plain values instead of the
 * parsing library's own types, which keeps that logic - and its tests -
 * independent of which library reads the feed.
 */
final readonly class IcsOccurrence
{
    /**
     * @param string              $uid     the source VEVENT's UID, empty when the feed omits it
     * @param string              $summary SUMMARY, untrimmed and unbounded; the caller enforces column widths
     * @param \DateTimeImmutable  $start   DTSTART of this occurrence, expressed in the target zone
     * @param ?\DateTimeImmutable $end     DTEND of this occurrence in the target zone, null when the feed states none
     * @param bool                $allDay  true when DTSTART is a date without a time (VALUE=DATE)
     */
    public function __construct(
        public string $uid,
        public string $summary,
        public \DateTimeImmutable $start,
        public ?\DateTimeImmutable $end,
        public bool $allDay,
    ) {
    }
}
