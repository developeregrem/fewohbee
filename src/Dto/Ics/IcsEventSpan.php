<?php

declare(strict_types=1);

namespace App\Dto\Ics;

/**
 * The days a single VEVENT covers, plus the clock times bounding it.
 *
 * Produced by IcsEventSpanResolver and consumed by CalendarEntrySyncService,
 * which turns each date into one CalendarEntry row.
 */
final readonly class IcsEventSpan
{
    /**
     * @param list<\DateTimeImmutable> $dates      one midnight per covered day, ascending
     * @param ?\DateTimeImmutable      $startTime  time of day the event begins, null when it is all-day
     * @param ?\DateTimeImmutable      $endTime    time of day the event ends, placed on the last covered
     *                                             day; null when that day runs to its end anyway
     */
    public function __construct(
        public array $dates,
        public ?\DateTimeImmutable $startTime,
        public ?\DateTimeImmutable $endTime,
    ) {
    }

    /** The day the end time belongs to, i.e. the last one the event covers. */
    public function lastDate(): \DateTimeImmutable
    {
        return $this->dates[array_key_last($this->dates)];
    }
}
