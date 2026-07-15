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
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalendarEntryRepository $repo,
        private readonly IcsEventParser $icsParser,
    ) {
    }

    /**
     * Fetches the ICS content from $calendar's configured URL and syncs it.
     * Returns the number of entries imported, or null if no URL is
     * configured at all.
     */
    public function sync(Calendar $calendar): ?int
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

    public function importIcsString(Calendar $calendar, string $icsData): int
    {
        if (!$this->icsParser->isValidCalendar($icsData)) {
            throw new CalendarSyncException('ICS-Datei konnte nicht gelesen werden.');
        }

        $count = 0;

        try {
            foreach ($this->icsParser->parseEvents($icsData) as $event) {
                $summary = trim((string) ($event['SUMMARY'] ?? ''));
                if ('' === $summary) {
                    continue;
                }

                $count += $this->upsert($calendar, $event, $summary);
            }
        } catch (\Throwable $e) {
            throw new CalendarSyncException('ICS-Termine konnten nicht verarbeitet werden: '.$e->getMessage(), previous: $e);
        }

        // Recorded here (not by callers) so both the admin-form save path
        // and the calendars:sync cron command keep this in sync consistently.
        $calendar->setLastSyncedAt(new \DateTime());
        $calendar->setLastSyncCount($count);

        // Deliberately never deletes entries missing from this import (e.g.
        // last year's dates dropping out of a rolling ICS feed) - a
        // confirmed entry is a historical record (see the Facility
        // overview) and re-syncing must not be able to erase it. Stale,
        // never-confirmed entries can still be removed manually.
        $this->em->flush();

        return $count;
    }

    /** @param array<string, string> $event */
    private function upsert(Calendar $calendar, array $event, string $summary): int
    {
        $dtStartRaw = $event['DTSTART'] ?? null;
        if (null === $dtStartRaw) {
            return 0;
        }

        $dtStart = $this->icsParser->parseDate($dtStartRaw);
        if (null === $dtStart) {
            return 0;
        }

        $dateKey = $dtStart->format('Ymd');

        $rawUid = trim((string) ($event['UID'] ?? ''));
        // Fallback for ICS files without UIDs: derive a stable key from
        // content + date so re-imports still upsert instead of duplicating.
        // Prefixed with the calendar id so the same UID in two different
        // calendars' feeds can't collide.
        $uid = 'cal'.$calendar->getId().'-'.('' !== $rawUid ? $rawUid.'-'.$dateKey : md5($summary.'-'.$dateKey));

        $entry = $this->repo->findOneBy(['icsUid' => $uid]) ?? new CalendarEntry();
        $entry->setCalendar($calendar)
            ->setIcsUid($uid)
            ->setDate($dtStart->setTime(0, 0))
            ->setTitle($summary);

        $this->em->persist($entry);

        return 1;
    }
}
