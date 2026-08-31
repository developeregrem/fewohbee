<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync;

use App\Dto\CalendarSync\CalendarEntrySyncResult;
use App\Entity\Calendar;
use App\Entity\CalendarEntry;
use App\Repository\CalendarEntryRepository;
use App\Exception\CalendarSyncException;
use App\Service\Calendar\Sync\Ics\IcsEventSpanResolver;
use App\Service\Calendar\Sync\Ics\IcsFeedClient;
use App\Service\Calendar\Sync\Ics\IcsOccurrenceReader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Imports entries from an ICS source (URL, configured per Calendar, or a
 * one-time file upload passed in directly) into CalendarEntry rows.
 *
 * RRULE-recurring events are expanded into one entry per occurrence by
 * IcsOccurrenceReader. Because such a rule can be unbounded, the import runs
 * against a window: nothing is filed further ahead than EXPAND_YEARS_AHEAD,
 * and nothing is filed in the past at all - see reconcile() for why entries
 * that have merely aged into the past are nevertheless kept.
 *
 * Events whose period cannot be read (see IcsEventSpanResolver) or whose
 * recurrence cannot be evaluated are discarded and counted - a feed is
 * untrusted input, and a guessed date is worse than a reported skip.
 */
class CalendarEntrySyncService
{
    private const OUTCOME_NEW = 'new';
    private const OUTCOME_UPDATED = 'updated';
    private const OUTCOME_UNCHANGED = 'unchanged';

    /**
     * Column widths of CalendarEntry::$title and ::$sourceUid. A feed is
     * untrusted input, so an over-long SUMMARY or UID must not reach the
     * flush: the insert would fail, and Doctrine closes the EntityManager on
     * a failed flush - which takes every calendar synced after this one down
     * with it, not just the offending entry.
     */
    private const MAX_TITLE_LENGTH = 100;
    private const MAX_SOURCE_UID_LENGTH = 255;

    /**
     * How far ahead a recurrence is materialised. An RRULE without UNTIL or
     * COUNT never ends, so the rows have to stop somewhere; the window moves
     * with every sync, which is what keeps a series topped up.
     */
    private const EXPAND_YEARS_AHEAD = 2;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalendarEntryRepository $repo,
        private readonly IcsOccurrenceReader $icsReader,
        private readonly IcsFeedClient $feedClient,
        private readonly IcsEventSpanResolver $spanResolver,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Fetches the ICS content from $calendar's configured URL and syncs it.
     * Returns null if no URL is configured at all.
     */
    public function sync(Calendar $calendar): ?CalendarEntrySyncResult
    {
        $url = $calendar->getIcsUrl();
        if (null === $url) {
            return null;
        }

        $content = $this->feedClient->fetch($url);

        return $this->importIcsString($calendar, $content);
    }

    public function importIcsString(Calendar $calendar, string $icsData): CalendarEntrySyncResult
    {
        if (!$this->icsReader->isValidCalendar($icsData)) {
            throw new CalendarSyncException('calendar.sync.error.invalid_ical');
        }

        $new = 0;
        $updated = 0;
        $unchanged = 0;
        $skippedInvalid = 0;

        // The zone a feed's instants are expressed in for storage. It comes
        // from PHP's date.timezone, which is the application's one timezone
        // source - the same one Doctrine hydrates zone-less DATETIME columns
        // in and Twig's date filter renders through.
        $zone = new \DateTimeZone(date_default_timezone_get());

        try {
            $today = $this->clock->now()->setTimezone($zone)->setTime(0, 0);
            $windowEnd = $today->modify('+'.self::EXPAND_YEARS_AHEAD.' years');

            // From today, not from the feed's own beginning: an occurrence
            // entirely in the past is not read at all, so it cannot create a
            // row. One still running is - fastForward() goes by the end - so a
            // multi-day event keeps the days it has left.
            $read = $this->icsReader->read($icsData, $zone, $today, $windowEnd);
            $skippedInvalid += $read->skipped;

            // Collected grouped by source event, so each event is reconciled
            // against the database once with all of its dates known - two
            // VEVENTs sharing a UID (or a UID-less feed repeating a summary)
            // then merge instead of fighting over the same rows.
            $occurrences = [];
            foreach ($read->occurrences as $occurrence) {
                $summary = trim($occurrence->summary);
                if ('' === $summary) {
                    continue;
                }

                // Truncated here rather than at the assignment sites so the
                // stored title and the value hashed into a UID-less feed's
                // source id stay the same string - otherwise a re-import
                // would keep seeing a "changed" title.
                $summary = mb_substr($summary, 0, self::MAX_TITLE_LENGTH);

                // An event whose period cannot be read - no DTSTART, a DTEND
                // not after it, an absurd span - is discarded rather than
                // filed under a guessed day, and counted so the caller can
                // say so instead of leaving the user with entries that
                // silently never appeared.
                $span = $this->spanResolver->resolve($occurrence);
                if (null === $span || [] === $span->dates) {
                    ++$skippedInvalid;
                    continue;
                }
                $lastIndex = array_key_last($span->dates);

                $sourceUid = $this->buildSourceUid($calendar, $occurrence->uid, $summary, $span->dates[0]);
                $occurrences[$sourceUid] ??= ['dates' => [], 'summaries' => [], 'times' => [], 'endTimes' => []];

                // What earlier VEVENTs sharing this UID already recorded. Read
                // out first so the loop below can fall back to it: a later
                // event must not blank out a time an earlier one already put
                // on the same day.
                $knownTimes = $occurrences[$sourceUid]['times'];
                $knownEndTimes = $occurrences[$sourceUid]['endTimes'];

                foreach ($span->dates as $index => $date) {
                    $dateKey = $date->format('Y-m-d');
                    $occurrences[$sourceUid]['dates'][$dateKey] = $date;
                    // Per date, not per event: a RECURRENCE-ID override is a second
                    // VEVENT under the same UID carrying its own SUMMARY, and the day
                    // it moved to has to read as that one rather than as the series.
                    $occurrences[$sourceUid]['summaries'][$dateKey] = $summary;
                    // The day the event starts on carries the start time and
                    // the day it ends on the end time; the days in between run
                    // all day by definition.
                    $occurrences[$sourceUid]['times'][$dateKey] = (0 === $index ? $span->startTime : null)
                        ?? ($knownTimes[$dateKey] ?? null);
                    $occurrences[$sourceUid]['endTimes'][$dateKey] = ($index === $lastIndex ? $span->endTime : null)
                        ?? ($knownEndTimes[$dateKey] ?? null);
                }
            }

            foreach ($occurrences as $sourceUid => $grouped) {
                foreach ($this->reconcile($calendar, $sourceUid, $grouped['summaries'], $grouped['dates'], $grouped['times'], $grouped['endTimes'], $today) as $outcome) {
                    match ($outcome) {
                        self::OUTCOME_NEW => $new++,
                        self::OUTCOME_UPDATED => $updated++,
                        self::OUTCOME_UNCHANGED => $unchanged++,
                    };
                }
            }

            $result = new CalendarEntrySyncResult($new, $updated, $unchanged, $skippedInvalid);

            // Recorded here (not by callers) so both the admin-form save path
            // and the calendars:sync cron command keep this in sync consistently.
            $calendar->setLastSyncedAt(new \DateTime());
            $calendar->setLastSyncCount($result->total());
            $calendar->setLastSyncNewCount($result->new);
            $calendar->setLastSyncUpdatedCount($result->updated);
            $calendar->setLastSyncUnchangedCount($result->unchanged);

            // An event that dropped out of the feed entirely (e.g. last
            // year's dates leaving a rolling window) is never reconciled and
            // so is never touched - only days an event still in the feed no
            // longer covers are cleaned up, and confirmed entries not even
            // then. Stale entries can still be removed manually.
            $this->em->flush();
        } catch (\Throwable $e) {
            throw new CalendarSyncException('calendar.sync.error.processing', previous: $e);
        }

        return $result;
    }

    /**
     * Compares two optional times by wall clock, so a re-sync does not report
     * every timed entry as "updated" just because the objects differ.
     */
    private function sameTime(?\DateTimeImmutable $a, ?\DateTimeImmutable $b): bool
    {
        if (null === $a || null === $b) {
            return $a === $b;
        }

        return $a->format('H:i:s') === $b->format('H:i:s');
    }

    /**
     * Prefixed with the calendar id so the same UID in two different
     * calendars' feeds can't collide.
     *
     * A feed without UIDs (invalid per RFC 5545, but seen in the wild) falls
     * back to hashing summary + start date. That keeps re-imports stable,
     * but deliberately ties the identity to the date: without a UID there is
     * no way to tell a moved occurrence from an unrelated one, and the
     * date-independent identity below would then make every occurrence of a
     * repeating summary look like one event whose dates keep changing.
     */
    private function buildSourceUid(Calendar $calendar, string $rawUid, string $summary, \DateTimeImmutable $start): string
    {
        $rawUid = trim($rawUid);
        $suffix = '' !== $rawUid ? $rawUid : md5($summary.'-'.$start->format('Ymd'));

        $sourceUid = 'cal'.$calendar->getId().'-'.$suffix;

        // A UID long enough to overflow the column is replaced by a hash of
        // itself rather than cut off: truncation would map two feed events
        // sharing a long prefix onto one id, and they would then fight over
        // the same rows on every sync. Hashing stays deterministic, so
        // re-imports keep matching the entries they created.
        if (mb_strlen($sourceUid) > self::MAX_SOURCE_UID_LENGTH) {
            $sourceUid = 'cal'.$calendar->getId().'-'.md5($suffix);
        }

        return $sourceUid;
    }

    /**
     * Brings the entries stored for one source event in line with the dates
     * that event now covers.
     *
     * Entries are matched to dates rather than recreated, so an occurrence
     * moved in the source (same UID, different day) updates the entry it
     * already has instead of leaving the old day behind as an orphan and
     * inserting a second one.
     *
     * Confirmed entries are a historical record (see deleteUnconfirmedPast())
     * and are never rewritten or removed here: one still matching a source
     * date keeps it, one whose date is gone is left alone rather than being
     * reused for another day.
     *
     * @param array<string, string>              $summaries keyed by Y-m-d; a moved occurrence
     *                                                      carries its own title, so this is
     *                                                      per date rather than per event
     * @param array<string, \DateTimeImmutable>  $dates    keyed by Y-m-d
     * @param array<string, ?\DateTimeImmutable> $times    keyed by Y-m-d, null where the day has no start time
     * @param array<string, ?\DateTimeImmutable> $endTimes keyed by Y-m-d, null where the day has no end time
     *
     * @return list<string> one OUTCOME_* per entry touched or left in place
     */
    private function reconcile(Calendar $calendar, string $sourceUid, array $summaries, array $dates, array $times, array $endTimes, \DateTimeImmutable $today): array
    {
        ksort($dates);
        $existing = $this->repo->findBySource($calendar, $sourceUid);

        // Nothing is filed behind today. A still-running multi-day event is
        // read whole - it has to be, or its remaining days would be lost - so
        // the days of it that have passed are dropped here rather than turned
        // into rows nobody asked for.
        foreach ($dates as $dateKey => $date) {
            if ($date < $today) {
                unset($dates[$dateKey], $summaries[$dateKey], $times[$dateKey], $endTimes[$dateKey]);
            }
        }

        $matched = [];
        $spare = [];
        foreach ($existing as $entry) {
            // A row for a day that has passed is left alone entirely: neither
            // matched, nor reused for another date, nor removed. It was filed
            // when it was still ahead, and clearing it out is the user's
            // decision through deleteUnconfirmedPast() - not something a sync
            // does behind their back an hour later.
            if ($entry->getDate() < $today) {
                continue;
            }

            $key = $entry->getDate()->format('Y-m-d');
            if (isset($dates[$key]) && !isset($matched[$key])) {
                $matched[$key] = $entry;
            } else {
                $spare[] = $entry;
            }
        }

        $outcomes = [];
        foreach ($matched as $dateKey => $entry) {
            $summary = $summaries[$dateKey] ?? '';
            $time = $times[$dateKey] ?? null;
            $endTime = $endTimes[$dateKey] ?? null;
            if ($entry->isConfirmed()
                || ($entry->getTitle() === $summary
                    && $this->sameTime($entry->getTime(), $time)
                    && $this->sameTime($entry->getEndTime(), $endTime))
            ) {
                $outcomes[] = self::OUTCOME_UNCHANGED;
                continue;
            }
            $entry->setTitle($summary)->setTime($time)->setEndTime($endTime);
            $outcomes[] = self::OUTCOME_UPDATED;
        }

        foreach (array_diff_key($dates, $matched) as $dateKey => $date) {
            $summary = $summaries[$dateKey] ?? '';
            $time = $times[$dateKey] ?? null;
            $endTime = $endTimes[$dateKey] ?? null;
            $entry = $this->takeReusable($spare);
            if (null !== $entry) {
                $entry->setDate($date)->setTitle($summary)->setTime($time)->setEndTime($endTime);
                $outcomes[] = self::OUTCOME_UPDATED;
                continue;
            }

            $entry = (new CalendarEntry())
                ->setCalendar($calendar)
                ->setSourceUid($sourceUid)
                ->setDate($date)
                ->setTitle($summary)
                ->setTime($time)
                ->setEndTime($endTime);
            $this->em->persist($entry);
            $outcomes[] = self::OUTCOME_NEW;
        }

        // Whatever is left belongs to days this event no longer covers.
        foreach ($spare as $entry) {
            if ($entry->isConfirmed()) {
                $outcomes[] = self::OUTCOME_UNCHANGED;
                continue;
            }
            $this->em->remove($entry);
        }

        return $outcomes;
    }

    /**
     * Pops the first entry that may be re-dated, skipping confirmed ones.
     *
     * @param array<int, CalendarEntry> $spare
     */
    private function takeReusable(array &$spare): ?CalendarEntry
    {
        foreach ($spare as $i => $entry) {
            if (!$entry->isConfirmed()) {
                unset($spare[$i]);

                return $entry;
            }
        }

        return null;
    }
}
