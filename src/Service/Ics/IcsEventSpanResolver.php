<?php

declare(strict_types=1);

namespace App\Service\Ics;

use App\Dto\Ics\IcsEventSpan;
use App\Dto\Ics\IcsOccurrence;
use App\Service\CalendarEntryTimeRules;

/**
 * Turns one VEVENT's raw DTSTART/DTEND properties into the days it covers and
 * the clock times bounding them.
 *
 * Split out of CalendarEntrySyncService so the date arithmetic - which carries
 * every RFC 5545 subtlety this import has to get right - can be tested without
 * a database, and so the sync service is left with reconciliation only.
 *
 * Multi-day events are expanded into one day each, the same way an
 * RRULE-recurring source is expected to list one VEVENT per occurrence. Every
 * other consumer of CalendarEntry (reminders, cleanup, the year-overview
 * popover, confirmation) already thinks in single days, so this keeps that
 * true instead of teaching each of them about ranges.
 */
final class IcsEventSpanResolver
{
    /**
     * Upper bound on how many days a single VEVENT's DTSTART..DTEND span is
     * expanded into - a malformed or absurd DTEND in an external ICS feed
     * (untrusted input) must not be able to spawn an unbounded number of
     * rows. No real waste/vacation/maintenance calendar needs a single
     * event longer than a year.
     */
    public const MAX_EVENT_SPAN_DAYS = 366;

    public function __construct(
        private readonly CalendarEntryTimeRules $timeRules,
    ) {
    }

    /**
     * Resolves one occurrence, or returns null when it is not usable and must
     * be discarded.
     *
     * The occurrence already carries its instants in the target zone, because
     * the zone decides the calendar day as much as the clock time: a feed's
     * 20260814T230000Z is the 15th in Europe/Berlin, not the 14th.
     *
     * Discarded (null) when:
     * - DTEND is not after DTSTART - RFC 5545 requires it to be later, so such
     *   an event carries no usable period. A same-value DTEND is rejected too:
     *   feeds write it both for a zero-length event and, wrongly, for a
     *   single all-day one, and guessing which was meant would silently
     *   invent a day the source never stated;
     * - the span exceeds MAX_EVENT_SPAN_DAYS.
     */
    public function resolve(IcsOccurrence $occurrence): ?IcsEventSpan
    {
        $allDay = $occurrence->allDay;

        // Normalised before anything compares them, so the checks below, the
        // day loop and the stored value all work on the same resolution.
        $start = $this->toWholeMinutes($occurrence->start);
        $startDay = $start->setTime(0, 0);

        $end = null !== $occurrence->end ? $this->toWholeMinutes($occurrence->end) : null;
        // Checked after normalising, so an event shorter than a minute counts
        // as the zero-length period it becomes rather than slipping through
        // on seconds the rest of the application cannot represent.
        if (null !== $end && $end <= $start) {
            return null;
        }

        $endExclusive = $this->resolveEndExclusive($startDay, $end, $allDay);
        if ($startDay->diff($endExclusive)->days > self::MAX_EVENT_SPAN_DAYS) {
            return null;
        }

        $dates = [];
        for ($date = $startDay; $date < $endExclusive; $date = $date->modify('+1 day')) {
            $dates[] = $date;
        }

        return new IcsEventSpan(
            dates: $dates,
            startTime: $allDay ? null : $start,
            endTime: $this->resolveEndTime($startDay, $dates, $end, $allDay),
        );
    }

    /**
     * The first day the event no longer covers - the exclusive bound the day
     * loop runs up to.
     *
     * DTEND is exclusive only for all-day events per RFC 5545: a 3-day event
     * Aug 1-3 has DTSTART=20260801, DTEND=20260804. For an event with a time
     * DTEND is the actual moment it stops, so the day it falls on is still
     * covered - unless it stops exactly at midnight, which belongs to the day
     * before. Treating both alike is what used to drop the closing day of
     * "Aug 14 13:00 - Aug 16 14:00".
     */
    private function resolveEndExclusive(\DateTimeImmutable $startDay, ?\DateTimeImmutable $end, bool $allDay): \DateTimeImmutable
    {
        if (null === $end) {
            return $startDay->modify('+1 day');
        }

        $endDay = $end->setTime(0, 0);
        $exclusive = ($allDay || $end == $endDay) ? $endDay : $endDay->modify('+1 day');

        // A DTEND inside the start day (13:00 - 14:00) resolves to that same
        // day, which the loop above would then skip entirely.
        return $exclusive > $startDay ? $exclusive : $startDay->modify('+1 day');
    }

    /**
     * The end time stored on the last covered day, or null when that day has
     * none worth stating.
     *
     * An all-day event has no clock times at all. An event ending at midnight
     * runs to the end of its last day; that is information only where the day
     * also has a start time ("18:00 - 00:00"). On a later day the entry simply
     * covers the whole day, which is what an all-day entry already says, so a
     * lone "- 00:00" would read as ending at the day's *beginning* instead.
     *
     * @param list<\DateTimeImmutable> $dates
     */
    private function resolveEndTime(\DateTimeImmutable $startDay, array $dates, ?\DateTimeImmutable $end, bool $allDay): ?\DateTimeImmutable
    {
        if ($allDay || null === $end) {
            return null;
        }

        $lastDay = $dates[array_key_last($dates)];

        return $this->timeRules->endTimeForClosingDay($end, $lastDay == $startDay);
    }

    /**
     * Drops seconds from a parsed value.
     *
     * The entry form works in whole minutes, so an imported time has to as
     * well - otherwise the two halves of the application disagree about the
     * same instant. A feed's 00:00:30 in particular is midnight to
     * CalendarEntryTimeRules but not to the day loop below, which would then
     * cover an extra calendar day for those thirty seconds.
     */
    private function toWholeMinutes(\DateTimeImmutable $value): \DateTimeImmutable
    {
        return $value->setTime((int) $value->format('H'), (int) $value->format('i'));
    }
}
