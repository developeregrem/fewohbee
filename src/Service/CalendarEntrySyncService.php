<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Calendar;
use App\Entity\CalendarEntry;
use App\Repository\CalendarEntryRepository;
use App\Service\Exception\CalendarSyncException;
use App\Service\Ics\IcsEventParser;
use Doctrine\ORM\EntityManagerInterface;

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

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalendarEntryRepository $repo,
        private readonly IcsEventParser $icsParser,
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

        $content = @file_get_contents($url);
        if (false === $content) {
            throw new CalendarSyncException('ICS-URL konnte nicht abgerufen werden: '.$url);
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
            foreach ($this->icsParser->parseEvents($icsData) as $event) {
                $summary = trim((string) ($event['SUMMARY'] ?? ''));
                if ('' === $summary) {
                    continue;
                }

                match ($this->upsert($calendar, $event, $summary)) {
                    self::OUTCOME_NEW => $new++,
                    self::OUTCOME_UPDATED => $updated++,
                    self::OUTCOME_UNCHANGED => $unchanged++,
                    null => null,
                };
            }
        } catch (\Throwable $e) {
            throw new CalendarSyncException('ICS-Termine konnten nicht verarbeitet werden: '.$e->getMessage(), previous: $e);
        }

        $result = new CalendarEntrySyncResult($new, $updated, $unchanged);

        // Recorded here (not by callers) so both the admin-form save path
        // and the calendars:sync cron command keep this in sync consistently.
        $calendar->setLastSyncedAt(new \DateTime());
        $calendar->setLastSyncCount($result->total());

        // Deliberately never deletes entries missing from this import (e.g.
        // last year's dates dropping out of a rolling ICS feed) - a
        // confirmed entry is a historical record (see the Facility
        // overview) and re-syncing must not be able to erase it. Stale,
        // never-confirmed entries can still be removed manually.
        $this->em->flush();

        return $result;
    }

    /**
     * @param array<string, string> $event
     *
     * Returns which of OUTCOME_NEW/OUTCOME_UPDATED/OUTCOME_UNCHANGED this
     * event resulted in, or null if the event was skipped (no usable
     * DTSTART).
     */
    private function upsert(Calendar $calendar, array $event, string $summary): ?string
    {
        $dtStartRaw = $event['DTSTART'] ?? null;
        if (null === $dtStartRaw) {
            return null;
        }

        $dtStart = $this->icsParser->parseDate($dtStartRaw);
        if (null === $dtStart) {
            return null;
        }

        $date = $dtStart->setTime(0, 0);
        $dateKey = $dtStart->format('Ymd');

        $rawUid = trim((string) ($event['UID'] ?? ''));
        // Fallback for ICS files without UIDs: derive a stable key from
        // content + date so re-imports still upsert instead of duplicating.
        // Prefixed with the calendar id so the same UID in two different
        // calendars' feeds can't collide.
        $uid = 'cal'.$calendar->getId().'-'.('' !== $rawUid ? $rawUid.'-'.$dateKey : md5($summary.'-'.$dateKey));

        $entry = $this->repo->findOneBy(['icsUid' => $uid]);
        $isNew = null === $entry;
        $unchanged = !$isNew && $entry->getDate() == $date && $entry->getTitle() === $summary;

        $entry ??= new CalendarEntry();
        $entry->setCalendar($calendar)
            ->setIcsUid($uid)
            ->setDate($date)
            ->setTitle($summary);

        $this->em->persist($entry);

        return match (true) {
            $isNew => self::OUTCOME_NEW,
            $unchanged => self::OUTCOME_UNCHANGED,
            default => self::OUTCOME_UPDATED,
        };
    }
}
