<?php

declare(strict_types=1);

namespace App\Service\Ics;

use App\Dto\Ics\IcsOccurrence;
use App\Dto\Ics\IcsReadResult;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;

/**
 * Reads an ICS feed into plain occurrences, expanding RRULE-recurring events
 * into one instance per date.
 *
 * Built on sabre/vobject's EventIterator rather than VCalendar::expand():
 * expand() rewrites every instant into the zone passed to it (UTC by default)
 * and fails for the whole calendar when a single event carries an unusable
 * recurrence, while the iterator works per event, so one broken VEVENT is
 * discarded and counted while the rest of the feed still imports.
 */
class IcsOccurrenceReader
{
    /**
     * Upper bound on the instances one VEVENT may contribute, independent of
     * the caller's window. A feed is untrusted input, and a rule such as
     * FREQ=HOURLY would otherwise fill a two-year window with some seventeen
     * thousand rows. An event that exceeds this is discarded whole and
     * counted, not cut off partway. Two years of daily occurrences - 731 -
     * stay below it, so only rules finer than daily are affected.
     */
    public const MAX_OCCURRENCES_PER_EVENT = 1000;

    public function isValidCalendar(string $content): bool
    {
        return str_contains($content, 'BEGIN:VCALENDAR') && str_contains($content, 'END:VCALENDAR');
    }

    /**
     * Every occurrence starting inside [$from, $to), expressed in $zone.
     *
     * The window is half-open at the top so a recurrence landing exactly on
     * $to belongs to the next window rather than to both.
     *
     * @throws \Sabre\VObject\ParseException when the feed is structurally unreadable
     */
    public function read(string $content, \DateTimeZone $zone, \DateTimeImmutable $from, \DateTimeImmutable $to): IcsReadResult
    {
        $calendar = Reader::read($content, Reader::OPTION_FORGIVING);

        // Grouped by UID because a recurring event and its RECURRENCE-ID
        // overrides are separate VEVENTs that only together describe the
        // series - the iterator needs all of them at once to place a moved
        // occurrence on its new time instead of its original one.
        $groups = [];
        $withoutUid = [];
        foreach ($calendar->VEVENT ?? [] as $event) {
            $uid = trim((string) ($event->UID ?? ''));
            if ('' === $uid) {
                $withoutUid[] = $event;
                continue;
            }
            $groups[$uid] = true;
        }

        $occurrences = [];
        $skipped = 0;

        foreach (array_keys($groups) as $uid) {
            try {
                $iterator = new EventIterator($calendar, (string) $uid, $zone);
            } catch (\Throwable $exception) {
                // An unusable RRULE, or a UID whose only VEVENT is an orphaned
                // override. Counted rather than thrown so the feed's remaining
                // events still import.
                ++$skipped;
                continue;
            }

            $this->collect($iterator, (string) $uid, $zone, $from, $to, $occurrences, $skipped);
        }

        // A feed without UIDs is invalid per RFC 5545 but occurs in the wild.
        // Each such VEVENT stands alone: it cannot carry overrides, and it
        // must not be merged with the others into one nameless series.
        foreach ($withoutUid as $event) {
            try {
                $iterator = new EventIterator([$event], null, $zone);
            } catch (\Throwable $exception) {
                ++$skipped;
                continue;
            }

            $this->collect($iterator, '', $zone, $from, $to, $occurrences, $skipped);
        }

        return new IcsReadResult($occurrences, $skipped);
    }

    /**
     * @param list<IcsOccurrence> $occurrences
     */
    private function collect(
        EventIterator $iterator,
        string $uid,
        \DateTimeZone $zone,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array &$occurrences,
        int &$skipped,
    ): void {
        // Buffered per event so an event that turns out to exceed the cap can
        // be dropped whole. Handing over the first thousand of a runaway rule
        // would fill the calendar with rows nobody can tell apart from real
        // ones, and say nothing about it.
        $collected = [];

        try {
            $iterator->fastForward($from);

            while ($iterator->valid() && $iterator->getDtStart() < $to) {
                $event = $iterator->getEventObject();

                // Only an end the source actually stated is passed on. With
                // neither DTEND nor DURATION the iterator reports an end equal
                // to the start, which is indistinguishable from a feed writing
                // DTEND == DTSTART - and that one has to stay rejected, since
                // it is written both for a zero-length event and, wrongly, for
                // a single all-day one.
                $statesEnd = [] !== $event->select('DTEND') || [] !== $event->select('DURATION');

                // A DTSTART without a clock time (VALUE=DATE) marks an all-day
                // occurrence; the property class is what distinguishes the two.
                $dtStart = $event->select('DTSTART')[0] ?? null;

                // setTimezone is not optional: the iterator yields each instant
                // in the zone the source expressed it in, and the zone handed
                // to its constructor only fills in for floating times. Without
                // this a feed's 20260814T230000Z stays 23:00 UTC and would be
                // filed on the 14th instead of the 15th in Berlin.
                $collected[] = new IcsOccurrence(
                    uid: $uid,
                    summary: (string) ($event->SUMMARY ?? ''),
                    start: $iterator->getDtStart()->setTimezone($zone),
                    end: $statesEnd ? $iterator->getDtEnd()?->setTimezone($zone) : null,
                    allDay: !($dtStart instanceof DateTimeProperty && $dtStart->hasTime()),
                );

                if (\count($collected) > self::MAX_OCCURRENCES_PER_EVENT) {
                    ++$skipped;

                    return;
                }
                $iterator->next();
            }
        } catch (\Throwable $exception) {
            // Recurrence rules are evaluated lazily, so a rule that only turns
            // out to be unusable partway through lands here rather than at
            // construction. What it produced before failing is still good.
            ++$skipped;
        }

        foreach ($collected as $occurrence) {
            $occurrences[] = $occurrence;
        }
    }

}
