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
 * expresses collection dates via an RRULE-recurring VEVENT isn't handled.
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

        try {
            $seenUids = [];
            foreach ($this->icsParser->parseEvents($icsData) as $event) {
                $summary = trim((string) ($event['SUMMARY'] ?? ''));
                if ('' === $summary) {
                    continue;
                }

                foreach ($this->upsert($calendar, $event, $summary, $seenUids) as $outcome) {
                    match ($outcome) {
                        self::OUTCOME_NEW => $new++,
                        self::OUTCOME_UPDATED => $updated++,
                        self::OUTCOME_UNCHANGED => $unchanged++,
                    };
                }
            }

            $result = new CalendarEntrySyncResult($new, $updated, $unchanged);

            // Recorded here (not by callers) so both the admin-form save path
            // and the calendars:sync cron command keep this in sync consistently.
            $calendar->setLastSyncedAt(new \DateTime());
            $calendar->setLastSyncCount($result->total());
            $calendar->setLastSyncNewCount($result->new);
            $calendar->setLastSyncUpdatedCount($result->updated);
            $calendar->setLastSyncUnchangedCount($result->unchanged);

            // Deliberately never deletes entries missing from this import (e.g.
            // last year's dates dropping out of a rolling ICS feed) - a
            // confirmed entry is a historical record and re-syncing must not
            // be able to erase it. Stale, never-confirmed entries can still
            // be removed manually.
            $this->em->flush();
        } catch (\Throwable $e) {
            throw new CalendarSyncException('ICS-Termine konnten nicht verarbeitet werden: '.$e->getMessage(), previous: $e);
        }

        return $result;
    }

    /**
     * @param array<string, string>        $event
     * @param array<string, CalendarEntry> $seenUids entries already upserted
     *                                                by this import call,
     *                                                keyed by icsUid - since
     *                                                nothing is flushed until
     *                                                the whole import
     *                                                finishes, a second event
     *                                                that hashes to the same
     *                                                fallback UID (no UID in
     *                                                the source, identical
     *                                                summary+date) wouldn't
     *                                                be found by a fresh DB
     *                                                lookup and would
     *                                                otherwise be persisted
     *                                                as a duplicate, tripping
     *                                                the unique constraint at
     *                                                flush time
     *
     * Multi-day events (DTSTART..DTEND) are expanded into one CalendarEntry
     * per day, the same way an RRULE-recurring source is expected to list
     * one VEVENT per occurrence - every other consumer of CalendarEntry
     * (reminders, cleanup, the year-overview popover, confirmation) already
     * thinks in single days, so this keeps that true instead of teaching
     * each of them about ranges.
     *
     * @return list<string> one OUTCOME_* per day the event spans, in order;
     *                      empty if skipped (no usable DTSTART, or a DTEND
     *                      implying a span over MAX_EVENT_SPAN_DAYS)
     */
    private function upsert(Calendar $calendar, array $event, string $summary, array &$seenUids): array
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

        $rawUid = trim((string) ($event['UID'] ?? ''));

        $outcomes = [];
        for ($date = $start; $date < $end; $date = $date->modify('+1 day')) {
            $dateKey = $date->format('Ymd');

            // Fallback for ICS files without UIDs: derive a stable key from
            // content + date so re-imports still upsert instead of
            // duplicating. Prefixed with the calendar id so the same UID in
            // two different calendars' feeds can't collide. The date suffix
            // also disambiguates each day of a multi-day event.
            $uid = 'cal'.$calendar->getId().'-'.('' !== $rawUid ? $rawUid.'-'.$dateKey : md5($summary.'-'.$dateKey));

            $entry = $seenUids[$uid] ?? $this->repo->findOneBy(['icsUid' => $uid]);
            $isNew = null === $entry;
            // A confirmed entry is a historical record (see
            // deleteUnconfirmedPast()) - re-syncing must not be able to
            // change what it says either, not just avoid deleting it.
            // Reported as unchanged since nothing in the DB was touched.
            $isConfirmed = !$isNew && $entry->isConfirmed();
            $unchanged = !$isNew && ($isConfirmed || ($entry->getDate() == $date && $entry->getTitle() === $summary));

            $entry ??= new CalendarEntry();
            $entry->setCalendar($calendar)->setIcsUid($uid);
            if (!$isConfirmed) {
                $entry->setDate($date)->setTitle($summary);
            }

            $this->em->persist($entry);
            $seenUids[$uid] = $entry;

            $outcomes[] = match (true) {
                $isNew => self::OUTCOME_NEW,
                $unchanged => self::OUTCOME_UNCHANGED,
                default => self::OUTCOME_UPDATED,
            };
        }

        return $outcomes;
    }
}
