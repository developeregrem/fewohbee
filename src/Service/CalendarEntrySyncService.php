<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Calendar;
use App\Entity\CalendarEntry;
use App\Repository\CalendarEntryRepository;
use App\Service\Exception\CalendarSyncException;
use App\Service\Ics\IcsEventParser;
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
 */
class CalendarEntrySyncService
{
    private const OUTCOME_NEW = 'new';
    private const OUTCOME_UPDATED = 'updated';
    private const OUTCOME_UNCHANGED = 'unchanged';

    /**
     * Upper bound on how many days a single VEVENT's DTSTART..DTEND span is
     * expanded into - a malformed or absurd DTEND in an external ICS feed
     * (untrusted input) must not be able to spawn an unbounded number of
     * rows. No real waste/vacation/maintenance calendar needs a single
     * event longer than a year.
     */
    private const MAX_EVENT_SPAN_DAYS = 366;

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

                $dates = $this->resolveDates($event);
                if ([] === $dates) {
                    continue;
                }

                $sourceUid = $this->buildSourceUid($calendar, $event, $summary, $dates[0]);
                $occurrences[$sourceUid]['summary'] = $summary;
                foreach ($dates as $date) {
                    $occurrences[$sourceUid]['dates'][$date->format('Y-m-d')] = $date;
                }
            }

            foreach ($occurrences as $sourceUid => $occurrence) {
                foreach ($this->reconcile($calendar, $sourceUid, $occurrence['summary'], $occurrence['dates']) as $outcome) {
                    match ($outcome) {
                        self::OUTCOME_NEW => $new++,
                        self::OUTCOME_UPDATED => $updated++,
                        self::OUTCOME_UNCHANGED => $unchanged++,
                    };
                }
            }

            $result = new CalendarEntrySyncResult($new, $updated, $unchanged, $skippedRecurring);

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
     * Every day a single VEVENT covers.
     *
     * Multi-day events (DTSTART..DTEND) are expanded into one day each, the
     * same way an RRULE-recurring source is expected to list one VEVENT per
     * occurrence - every other consumer of CalendarEntry (reminders,
     * cleanup, the year-overview popover, confirmation) already thinks in
     * single days, so this keeps that true instead of teaching each of them
     * about ranges.
     *
     * @param array<string, string> $event
     *
     * @return list<\DateTimeImmutable> empty if the event has no usable
     *                                  DTSTART, or a DTEND implying a span
     *                                  over MAX_EVENT_SPAN_DAYS
     */
    private function resolveDates(array $event): array
    {
        $dtStartRaw = $event['DTSTART'] ?? null;
        if (null === $dtStartRaw) {
            return [];
        }

        $dtStart = $this->icsParser->parseDate($dtStartRaw);
        if (null === $dtStart) {
            return [];
        }
        $start = $dtStart->setTime(0, 0);

        // DTEND is exclusive for all-day events per RFC 5545 - a 3-day event
        // Aug 1-3 has DTSTART=20260801, DTEND=20260804. Falls back to a
        // single day when DTEND is absent, unparseable, or not after DTSTART.
        $dtEndRaw = $event['DTEND'] ?? null;
        $dtEnd = null !== $dtEndRaw ? $this->icsParser->parseDate($dtEndRaw) : null;
        $end = null !== $dtEnd ? $dtEnd->setTime(0, 0) : $start->modify('+1 day');
        if ($end <= $start) {
            $end = $start->modify('+1 day');
        }

        if ($start->diff($end)->days > self::MAX_EVENT_SPAN_DAYS) {
            return [];
        }

        $dates = [];
        for ($date = $start; $date < $end; $date = $date->modify('+1 day')) {
            $dates[] = $date;
        }

        return $dates;
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
     * @param array<string, \DateTimeImmutable> $dates keyed by Y-m-d
     *
     * @return list<string> one OUTCOME_* per entry touched or left in place
     */
    private function reconcile(Calendar $calendar, string $sourceUid, string $summary, array $dates): array
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
        foreach ($matched as $entry) {
            if ($entry->isConfirmed() || $entry->getTitle() === $summary) {
                $outcomes[] = self::OUTCOME_UNCHANGED;
                continue;
            }
            $entry->setTitle($summary);
            $outcomes[] = self::OUTCOME_UPDATED;
        }

        foreach (array_diff_key($dates, $matched) as $date) {
            $entry = $this->takeReusable($spare);
            if (null !== $entry) {
                $entry->setDate($date)->setTitle($summary);
                $outcomes[] = self::OUTCOME_UPDATED;
                continue;
            }

            $entry = (new CalendarEntry())
                ->setCalendar($calendar)
                ->setSourceUid($sourceUid)
                ->setDate($date)
                ->setTitle($summary);
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
