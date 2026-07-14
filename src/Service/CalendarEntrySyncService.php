<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Calendar;
use App\Entity\CalendarEntry;
use App\Repository\CalendarEntryRepository;
use App\Service\Exception\CalendarSyncException;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;

/**
 * Imports entries from an ICS source (uploaded file or a remote URL,
 * configured per Calendar) into CalendarEntry rows.
 *
 * Named CalendarEntrySyncService (not CalendarSyncService) to avoid
 * colliding with the pre-existing CalendarSyncService, which manages this
 * project's unrelated apartment-calendar export/subscription feature
 * (CalendarSync entity).
 *
 * Handles both single VEVENTs and recurring ones (RRULE), since the ICS
 * dialect varies by provider and can't be assumed.
 */
class CalendarEntrySyncService
{
    private const RECUR_HORIZON = '+2 years';
    private const RECUR_MAX_ITERATIONS = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalendarEntryRepository $repo,
        private readonly FilesystemOperator $calendarStorage,
    ) {
    }

    /**
     * Loads the ICS content configured on $calendar (upload takes
     * precedence over URL) and syncs it. Returns the number of entries
     * imported, or null if no source is configured at all.
     */
    public function sync(Calendar $calendar): ?int
    {
        $ics = $this->loadIcsContent($calendar);
        if (null === $ics) {
            return null;
        }

        return $this->importIcsString($calendar, $ics);
    }

    public function importIcsString(Calendar $calendar, string $icsData): int
    {
        try {
            $vcalendar = Reader::read($icsData);
        } catch (\Throwable $e) {
            throw new CalendarSyncException('ICS-Datei konnte nicht gelesen werden: '.$e->getMessage(), previous: $e);
        }

        $count = 0;
        $rangeEnd = new \DateTimeImmutable(self::RECUR_HORIZON);

        try {
            foreach ($vcalendar->VEVENT ?? [] as $veventComponent) {
                /** @var VEvent $veventComponent */
                $summary = trim((string) $veventComponent->SUMMARY);
                if ('' === $summary) {
                    continue;
                }

                if (isset($veventComponent->RRULE)) {
                    $count += $this->importRecurring($calendar, $vcalendar, $veventComponent, $summary, $rangeEnd);
                    continue;
                }

                $count += $this->upsert($calendar, $veventComponent, $summary);
            }
        } catch (\Throwable $e) {
            throw new CalendarSyncException('ICS-Termine konnten nicht verarbeitet werden: '.$e->getMessage(), previous: $e);
        }

        // Deliberately never deletes entries missing from this import (e.g.
        // last year's dates dropping out of a rolling ICS feed) - a
        // confirmed entry is a historical record (see the Facility
        // overview) and re-syncing must not be able to erase it. Stale,
        // never-confirmed entries can still be removed manually.
        $this->em->flush();

        return $count;
    }

    private function importRecurring(
        Calendar $calendar,
        \Sabre\VObject\Component\VCalendar $vcalendar,
        VEvent $masterEvent,
        string $summary,
        \DateTimeImmutable $rangeEnd,
    ): int {
        $uid = (string) ($masterEvent->UID ?? '');
        if ('' === $uid) {
            return $this->upsert($calendar, $masterEvent, $summary);
        }

        $it = new EventIterator($vcalendar, $uid);
        $count = 0;
        $iterations = 0;

        while ($it->valid() && $it->getDtStart() < $rangeEnd && $iterations < self::RECUR_MAX_ITERATIONS) {
            $count += $this->upsert($calendar, $it->getEventObject(), $summary);
            $it->next();
            ++$iterations;
        }

        return $count;
    }

    private function upsert(Calendar $calendar, VEvent $event, string $summary): int
    {
        $dtStart = $event->DTSTART->getDateTime();
        $dateKey = $dtStart->format('Ymd');

        $rawUid = trim((string) ($event->UID ?? ''));
        // Fallback for ICS files without UIDs: derive a stable key from
        // content + date so re-imports still upsert instead of duplicating.
        // Prefixed with the calendar id so the same UID in two different
        // calendars' feeds can't collide.
        $uid = 'cal'.$calendar->getId().'-'.('' !== $rawUid ? $rawUid.'-'.$dateKey : md5($summary.'-'.$dateKey));

        $entry = $this->repo->findOneBy(['icsUid' => $uid]) ?? new CalendarEntry();
        $entry->setCalendar($calendar)
            ->setIcsUid($uid)
            ->setDate(\DateTimeImmutable::createFromInterface($dtStart)->setTime(0, 0))
            ->setTitle($summary);

        $this->em->persist($entry);

        return 1;
    }

    private function loadIcsContent(Calendar $calendar): ?string
    {
        $filename = $calendar->getIcsFilename();
        if (null !== $filename) {
            if (!$this->calendarStorage->fileExists($filename)) {
                throw new CalendarSyncException('Hochgeladene ICS-Datei wurde nicht gefunden: '.$filename);
            }

            return $this->calendarStorage->read($filename);
        }

        $url = $calendar->getIcsUrl();
        if (null !== $url) {
            $content = @file_get_contents($url);
            if (false === $content) {
                throw new CalendarSyncException('ICS-URL konnte nicht abgerufen werden: '.$url);
            }

            return $content;
        }

        return null;
    }
}
