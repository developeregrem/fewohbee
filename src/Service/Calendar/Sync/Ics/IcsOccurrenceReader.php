<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\Ics;

use App\Dto\Ics\IcsOccurrence;
use App\Dto\Ics\IcsReadResult;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;

/**
 * Reads ICS feeds with Sabre and exposes library-independent event occurrences.
 *
 * Recurring events can either be expanded inside a bounded window for custom
 * calendars or read once as their raw master VEVENT for reservation portal feeds.
 */
class IcsOccurrenceReader
{
    /**
     * Upper bound on instances one recurring VEVENT may contribute.
     *
     * An event exceeding the cap is discarded whole so an untrusted hourly
     * recurrence cannot create thousands of database rows.
     */
    public const MAX_OCCURRENCES_PER_EVENT = 1000;

    public function isValidCalendar(string $content): bool
    {
        return str_contains($content, 'BEGIN:VCALENDAR') && str_contains($content, 'END:VCALENDAR');
    }

    /**
     * Read every raw VEVENT exactly once without expanding RRULE or RDATE.
     *
     * This mode preserves the reservation portal import contract: channel
     * managers publish one VEVENT per booking and recurrence rules are ignored.
     *
     * @throws \Sabre\VObject\ParseException when the feed is structurally unreadable
     */
    public function readEvents(string $content, \DateTimeZone $zone): IcsReadResult
    {
        $calendar = Reader::read($content, Reader::OPTION_FORGIVING);
        $events = $calendar->VEVENT ?? [];
        $occurrences = [];
        $skipped = 0;

        foreach ($events as $event) {
            try {
                $iterator = new EventIterator([$event], null, $zone);
                $occurrences[] = $this->createOccurrence(
                    $iterator,
                    trim((string) ($event->UID ?? '')),
                    $zone,
                );
            } catch (\Throwable) {
                ++$skipped;
            }
        }

        return new IcsReadResult($occurrences, $skipped, count($events));
    }

    /**
     * Expand every occurrence starting inside the half-open window [$from, $to).
     *
     * @throws \Sabre\VObject\ParseException when the feed is structurally unreadable
     */
    public function read(
        string $content,
        \DateTimeZone $zone,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): IcsReadResult {
        $calendar = Reader::read($content, Reader::OPTION_FORGIVING);
        $events = $calendar->VEVENT ?? [];

        // Recurrence overrides are separate VEVENTs sharing one UID. Grouping
        // them lets Sabre place a moved occurrence at its effective date.
        $groups = [];
        $withoutUid = [];
        foreach ($events as $event) {
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
            } catch (\Throwable) {
                ++$skipped;
                continue;
            }

            $this->collect($iterator, (string) $uid, $zone, $from, $to, $occurrences, $skipped);
        }

        // UID-less events cannot carry reliable recurrence overrides and are
        // intentionally evaluated independently.
        foreach ($withoutUid as $event) {
            try {
                $iterator = new EventIterator([$event], null, $zone);
            } catch (\Throwable) {
                ++$skipped;
                continue;
            }

            $this->collect($iterator, '', $zone, $from, $to, $occurrences, $skipped);
        }

        return new IcsReadResult($occurrences, $skipped, count($events));
    }

    /**
     * Collect all instances from one recurrence iterator inside the requested window.
     *
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
        $collected = [];

        try {
            $iterator->fastForward($from);

            while ($iterator->valid() && $iterator->getDtStart() < $to) {
                $collected[] = $this->createOccurrence($iterator, $uid, $zone);

                if (count($collected) > self::MAX_OCCURRENCES_PER_EVENT) {
                    ++$skipped;

                    return;
                }
                $iterator->next();
            }
        } catch (\Throwable) {
            // Recurrences are evaluated lazily. Instances collected before a
            // later invalid rule segment remain usable.
            ++$skipped;
        }

        foreach ($collected as $occurrence) {
            $occurrences[] = $occurrence;
        }
    }

    /**
     * Convert the iterator's current event into the shared plain-value DTO.
     *
     * @throws \UnexpectedValueException when Sabre yields no current DTSTART
     */
    private function createOccurrence(
        EventIterator $iterator,
        string $uid,
        \DateTimeZone $zone,
    ): IcsOccurrence {
        $event = $iterator->getEventObject();
        $start = $iterator->getDtStart();
        if (!$start instanceof \DateTimeImmutable) {
            throw new \UnexpectedValueException('VEVENT has no readable DTSTART.');
        }

        // Only an explicitly stated DTEND or DURATION is retained. Sabre
        // otherwise synthesizes an end equal to the start for timed events.
        $statesEnd = [] !== $event->select('DTEND') || [] !== $event->select('DURATION');
        $dtStart = $event->select('DTSTART')[0] ?? null;

        return new IcsOccurrence(
            uid: $uid,
            summary: (string) ($event->SUMMARY ?? ''),
            description: (string) ($event->DESCRIPTION ?? ''),
            start: $start->setTimezone($zone),
            end: $statesEnd ? $iterator->getDtEnd()?->setTimezone($zone) : null,
            allDay: !($dtStart instanceof DateTimeProperty && $dtStart->hasTime()),
        );
    }
}
