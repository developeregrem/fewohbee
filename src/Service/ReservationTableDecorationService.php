<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ReservationTable\DayCalendarEntry;
use App\Dto\ReservationTable\DayDecoration;
use App\Repository\CalendarEntryRepository;
use App\Repository\CalendarRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Resolves what a day column shows besides reservations: public holidays,
 * calendar entries, and the link to add one.
 *
 * Kept apart from ReservationTableService so that one stays a dependency-free
 * grid builder: everything needing the database or the router happens here,
 * and buildGrid() only attaches the finished map.
 */
class ReservationTableDecorationService
{
    public function __construct(
        private readonly CalendarEntryRepository $entryRepo,
        private readonly CalendarRepository $calendarRepo,
        private readonly CalendarService $calendarService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CalendarEntryTimeFormatter $timeFormatter,
    ) {
    }

    /**
     * One decoration per day of the visible period, keyed by Y-m-d.
     *
     * Calendar entries come from a single query for the period rather than a
     * lookup per day - the overview shows 30 to 180 days, so a per-day query
     * would be that many round trips for a feature that is usually empty.
     *
     * @param \DateTimeImmutable[] $days
     * @param bool $canManageEntries whether the viewer may create/edit/delete entries
     *                               (ROLE_RESERVATIONS; read-only staff only ever see them)
     *
     * @return array<string, DayDecoration>
     */
    public function buildForDays(
        array $days,
        string $holidayCountry,
        string $locale,
        bool $showCalendarEntries,
        bool $canManageEntries = false,
    ): array {
        if ([] === $days) {
            return [];
        }

        $entriesByDate = $showCalendarEntries ? $this->loadEntriesByDate($days, $canManageEntries) : [];

        // Offering "add entry" without a single calendar configured would send
        // the user to a form that can only 404 - it needs a calendar to put the
        // entry in. Reads as broken, so the link is left out instead.
        $canAddEntries = $showCalendarEntries && $canManageEntries && $this->calendarRepo->count([]) > 0;

        $decorations = [];
        foreach ($days as $day) {
            $dateKey = $day->format('Y-m-d');

            $holidays = [];
            foreach ($this->calendarService->getPublicdaysForDay(\DateTime::createFromInterface($day), $holidayCountry, $locale) as $holiday) {
                $holidays[] = $holiday->getName();
            }

            $decorations[$dateKey] = new DayDecoration(
                holidays: $holidays,
                calendarEntries: $entriesByDate[$dateKey] ?? [],
                newEntryUrl: $canAddEntries
                    ? $this->urlGenerator->generate('reservations.calendar_entry.new', ['date' => $dateKey])
                    : null,
            );
        }

        return $decorations;
    }

    /**
     * @param \DateTimeImmutable[] $days
     *
     * @return array<string, DayCalendarEntry[]>
     */
    private function loadEntriesByDate(array $days, bool $canManageEntries): array
    {
        $first = $days[array_key_first($days)];
        $last = $days[array_key_last($days)];

        $byDate = [];
        foreach ($this->entryRepo->findForPeriod($first, $last) as $entry) {
            $calendar = $entry->getCalendar();
            $dateKey = $entry->getDate()->format('Y-m-d');
            // The query orders by calendar name, so entries of one calendar
            // arrive together and a change of id marks the group boundary the
            // popover draws its separator on.
            $previous = $byDate[$dateKey][array_key_last($byDate[$dateKey] ?? [])] ?? null;

            $byDate[$dateKey][] = new DayCalendarEntry(
                id: $entry->getId(),
                title: $entry->getTitle(),
                calendarId: $calendar->getId(),
                calendarName: $calendar->getName(),
                color: $calendar->getColor(),
                // Null for read-only staff: the routes reject them anyway, so
                // the popover would only offer links into a 403.
                editUrl: $canManageEntries ? $this->urlGenerator->generate('reservations.calendar_entry.edit', ['id' => $entry->getId()]) : null,
                deleteUrl: $canManageEntries ? $this->urlGenerator->generate('reservations.calendar_entry.delete', ['id' => $entry->getId()]) : null,
                time: $this->timeFormatter->format($entry),
                startsCalendarGroup: null !== $previous && $previous->calendarId !== $calendar->getId(),
            );
        }

        return $byDate;
    }
}
