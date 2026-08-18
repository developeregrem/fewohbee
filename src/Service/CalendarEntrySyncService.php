<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Calendar;
use App\Entity\CalendarEntry;
use App\Repository\CalendarEntryRepository;
use App\Service\Exception\CalendarSyncException;
use App\Service\Ics\IcsEventParser;
use App\Service\Ics\IcsEventSpanResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Imports entries from an ICS source (URL, configured per Calendar, or a
 * one-time file upload passed in directly) into CalendarEntry rows.
 *
 * Named CalendarEntrySyncService (not CalendarSyncService) to avoid
 * colliding with the pre-existing CalendarSyncService, which manages this
 * project's unrelated apartment-calendar export/subscription feature
 * (CalendarSync entity).
 *
 * Only single VEVENTs are supported (one per occurrence) - a source that
 * expresses its dates via an RRULE-recurring VEVENT isn't expanded. Such
 * events are skipped and counted, so the caller can tell the user why a
 * birthday or holiday feed produced nothing, rather than storing one entry
 * on the original DTSTART where it would never be seen.
 *
 * Events whose period cannot be read at all (see IcsEventSpanResolver) are
 * discarded and counted the same way - a feed is untrusted input, and a
 * guessed date is worse than a reported skip.
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

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalendarEntryRepository $repo,
        private readonly IcsEventParser $icsParser,
        private readonly HttpClientInterface $httpClient,
        private readonly IcsEventSpanResolver $spanResolver,
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

        // Bounded timeout (matching CalendarImportService's ICS fetch) so one
        // unresponsive host can't stall the whole calendars:sync cron run,
        // which fetches every configured calendar sequentially in one process.
        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 10]);
            if (200 !== $response->getStatusCode()) {
                throw new CalendarSyncException('ICS-URL antwortete mit Status '.$response->getStatusCode().': '.$url);
            }
            $content = $response->getContent();
        } catch (ExceptionInterface $exception) {
            throw new CalendarSyncException('ICS-URL konnte nicht abgerufen werden: '.$url, previous: $exception);
        }

        return $this->importIcsString($calendar, $content);
    }

    public function importIcsString(Calendar $calendar, string $icsData): CalendarEntrySyncResult
    {
        if (!$this->icsParser->isValidCalendar($icsData)) {
            throw new CalendarSyncException('ICS-Datei konnte nicht gelesen werden.');
        }

        $new = 0;
        $updated = 0;
        $unchanged = 0;
        $skippedRecurring = 0;
        $skippedInvalid = 0;

        // The zone a feed's instants are expressed in for storage. It comes
        // from PHP's date.timezone, which is the application's one timezone
        // source - the same one Doctrine hydrates zone-less DATETIME columns
        // in and Twig's date filter renders through.
        $zone = new \DateTimeZone(date_default_timezone_get());

        try {
            // Collect the whole feed first, grouped by source event, so each
            // event is reconciled against the database once with all of its
            // dates known - two VEVENTs sharing a UID (or a UID-less feed
            // repeating a summary) then merge instead of fighting over the
            // same rows.
            $occurrences = [];
            foreach ($this->icsParser->parseEvents($icsData) as $event) {
                $summary = trim((string) ($event['SUMMARY'] ?? ''));
                if ('' === $summary) {
                    continue;
                }

                // A recurring event carries its repetition in the RRULE, which
                // is not expanded here. Importing it anyway would store a
                // single entry on DTSTART - for a birthday feed that is the
                // year of birth, decades in the past, where nobody will ever
                // see it while the import cheerfully reports success. Skipped
                // and counted instead, so the caller can name the reason.
                if ('' !== trim((string) ($event['RRULE'] ?? ''))) {
                    ++$skippedRecurring;
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
                $span = $this->spanResolver->resolve($event, $zone);
                if (null === $span || [] === $span->dates) {
                    ++$skippedInvalid;
                    continue;
                }
                $lastIndex = array_key_last($span->dates);

                $sourceUid = $this->buildSourceUid($calendar, $event, $summary, $span->dates[0]);
                $occurrences[$sourceUid] ??= ['summary' => $summary, 'dates' => [], 'times' => [], 'endTimes' => []];
                $occurrences[$sourceUid]['summary'] = $summary;

                // What earlier VEVENTs sharing this UID already recorded. Read
                // out first so the loop below can fall back to it: a later
                // event must not blank out a time an earlier one already put
                // on the same day.
                $knownTimes = $occurrences[$sourceUid]['times'];
                $knownEndTimes = $occurrences[$sourceUid]['endTimes'];

                foreach ($span->dates as $index => $date) {
                    $dateKey = $date->format('Y-m-d');
                    $occurrences[$sourceUid]['dates'][$dateKey] = $date;
                    // The day the event starts on carries the start time and
                    // the day it ends on the end time; the days in between run
                    // all day by definition.
                    $occurrences[$sourceUid]['times'][$dateKey] = (0 === $index ? $span->startTime : null)
                        ?? ($knownTimes[$dateKey] ?? null);
                    $occurrences[$sourceUid]['endTimes'][$dateKey] = ($index === $lastIndex ? $span->endTime : null)
                        ?? ($knownEndTimes[$dateKey] ?? null);
                }
            }

            foreach ($occurrences as $sourceUid => $occurrence) {
                foreach ($this->reconcile($calendar, $sourceUid, $occurrence['summary'], $occurrence['dates'], $occurrence['times'], $occurrence['endTimes']) as $outcome) {
                    match ($outcome) {
                        self::OUTCOME_NEW => $new++,
                        self::OUTCOME_UPDATED => $updated++,
                        self::OUTCOME_UNCHANGED => $unchanged++,
                    };
                }
            }

            $result = new CalendarEntrySyncResult($new, $updated, $unchanged, $skippedRecurring, $skippedInvalid);

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
            throw new CalendarSyncException('ICS-Termine konnten nicht verarbeitet werden: '.$e->getMessage(), previous: $e);
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
     *
     * @param array<string, string> $event
     */
    private function buildSourceUid(Calendar $calendar, array $event, string $summary, \DateTimeImmutable $start): string
    {
        $rawUid = trim((string) ($event['UID'] ?? ''));
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
     * @param array<string, \DateTimeImmutable>  $dates    keyed by Y-m-d
     * @param array<string, ?\DateTimeImmutable> $times    keyed by Y-m-d, null where the day has no start time
     * @param array<string, ?\DateTimeImmutable> $endTimes keyed by Y-m-d, null where the day has no end time
     *
     * @return list<string> one OUTCOME_* per entry touched or left in place
     */
    private function reconcile(Calendar $calendar, string $sourceUid, string $summary, array $dates, array $times = [], array $endTimes = []): array
    {
        ksort($dates);
        $existing = $this->repo->findBySource($calendar, $sourceUid);

        $matched = [];
        $spare = [];
        foreach ($existing as $entry) {
            $key = $entry->getDate()->format('Y-m-d');
            if (isset($dates[$key]) && !isset($matched[$key])) {
                $matched[$key] = $entry;
            } else {
                $spare[] = $entry;
            }
        }

        $outcomes = [];
        foreach ($matched as $dateKey => $entry) {
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
     * @param list<CalendarEntry> $spare
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
