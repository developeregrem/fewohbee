<?php

declare(strict_types=1);

namespace App\Dto\Ics;

/**
 * One occurrence of a VEVENT, resolved into plain values in the caller's time zone.
 *
 * Produced by IcsOccurrenceReader and consumed by calendar synchronization services.
 * The DTO keeps downstream business logic independent of Sabre's own object model.
 */
final readonly class IcsOccurrence
{
    /**
     * @param string              $uid         source VEVENT UID, empty when omitted
     * @param string              $summary     untrimmed SUMMARY
     * @param string              $description untrimmed DESCRIPTION
     * @param \DateTimeImmutable  $start       DTSTART expressed in the target zone
     * @param ?\DateTimeImmutable $end         DTEND expressed in the target zone, null when absent
     * @param bool                $allDay      true for a date-only DTSTART
     */
    public function __construct(
        public string $uid,
        public string $summary,
        public string $description,
        public \DateTimeImmutable $start,
        public ?\DateTimeImmutable $end,
        public bool $allDay,
    ) {
    }
}
