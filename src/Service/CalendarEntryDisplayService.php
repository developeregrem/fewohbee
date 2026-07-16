<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Calendar;
use App\Entity\CalendarEntry;
use App\Repository\CalendarEntryRepository;
use App\Repository\CalendarRepository;

/**
 * Looks up calendar entries per day for display in the reservation year
 * overview, next to public holidays - one accent line per calendar that
 * has an entry that day, stacked in the cell.
 *
 * Loads one year at a time and caches it for the lifetime of this instance
 * (Symfony creates a fresh instance per request under php-fpm/CLI, this
 * project's only runtimes) instead of querying per grid column/calendar -
 * the yearly overview calls this once per day column, which would
 * otherwise mean a couple hundred queries per page load.
 */
class CalendarEntryDisplayService
{
    /** @var array<string, CalendarEntry[]> */
    private array $entriesByDate = [];

    /** @var array<int, true> years already loaded into $entriesByDate */
    private array $warmedYears = [];

    /** @var Calendar[]|null */
    private ?array $calendars = null;

    public function __construct(
        private readonly CalendarEntryRepository $entryRepo,
        private readonly CalendarRepository $calendarRepo,
    ) {
    }

    /**
     * @return CalendarEntry[]
     */
    public function getForDay(\DateTimeInterface $date): array
    {
        $this->warmYear((int) $date->format('Y'));

        return $this->entriesByDate[$date->format('Y-m-d')] ?? [];
    }

    /**
     * @return Calendar[]
     */
    public function getAllCalendars(): array
    {
        $this->calendars ??= $this->calendarRepo->findAllOrdered();

        return $this->calendars;
    }

    private function warmYear(int $year): void
    {
        if (isset($this->warmedYears[$year])) {
            return;
        }
        $this->warmedYears[$year] = true;

        foreach ($this->entryRepo->findForYear($year) as $entry) {
            $this->entriesByDate[$entry->getDate()->format('Y-m-d')][] = $entry;
        }

        // Group same-day entries by calendar (alphabetically, matching the
        // Calendar management list) so the year-overview popover can tell
        // calendars apart with a separator line instead of an arbitrary mix.
        // Scoped to this year's dates only - re-sorting the whole map on
        // every warmYear() call would redo work for years already sorted.
        foreach ($this->entriesByDate as $dateKey => &$entries) {
            if ((int) substr($dateKey, 0, 4) === $year) {
                usort($entries, static fn (CalendarEntry $a, CalendarEntry $b) => $a->getCalendar()->getName() <=> $b->getCalendar()->getName());
            }
        }
        unset($entries);
    }
}
