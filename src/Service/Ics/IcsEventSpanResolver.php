<?php

declare(strict_types=1);

namespace App\Service\Ics;

use App\Dto\Ics\IcsEventSpan;
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
        private readonly IcsEventParser $parser,
        private readonly CalendarEntryTimeRules $timeRules,
    ) {
    }

    /**
     * Resolves a VEVENT property map, or returns null when the event is not
     * usable and must be discarded.
     *
     * Everything is resolved in $zone first, because the zone decides the
     * calendar day as much as the clock time: a feed's 20260814T230000Z is the
     * 15th in Europe/Berlin, not the 14th.
     *
     * Discarded (null) when:
     * - DTSTART is missing or unparseable - there is no day to file it under;
     * - DTEND is not after DTSTART - RFC 5545 requires it to be later, so such
     *   an event carries no usable period. A same-value DTEND is rejected too:
     *   feeds write it both for a zero-length event and, wrongly, for a
     *   single all-day one, and guessing which was meant would silently
     *   invent a day the source never stated;
     * - the span exceeds MAX_EVENT_SPAN_DAYS.
     *
     * @param array<string, string> $event flat property => value map, as produced by IcsEventParser
     */
    public function resolve(array $event, \DateTimeZone $zone): ?IcsEventSpan
    {
        $dtStartRaw = $event['DTSTART'] ?? null;
        if (null === $dtStartRaw) {
            return null;
        }

        $allDay = $this->parser->isDateOnly($dtStartRaw);

        $start = $this->parser->parseDateTimeInZone($dtStartRaw, $event['DTSTART'.IcsEventParser::PARAMS_SUFFIX] ?? null, $zone);
        if (null === $start) {
            return null;
        }
        $startDay = $start->setTime(0, 0);

        $dtEndRaw = $event['DTEND'] ?? null;
        $end = null !== $dtEndRaw
            ? $this->parser->parseDateTimeInZone($dtEndRaw, $event['DTEND'.IcsEventParser::PARAMS_SUFFIX] ?? null, $zone)
            : null;

        // An unparseable DTEND is not the same as an absent one: the event did
        // state a period and we failed to read it, so filing it as a single
        // day would put a made-up period in the calendar.
        if (null !== $dtEndRaw && null === $end) {
            return null;
        }
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
        if ($this->timeRules->endsAtMidnight($end) && $lastDay != $startDay) {
            return null;
        }

        return $end;
    }
}
