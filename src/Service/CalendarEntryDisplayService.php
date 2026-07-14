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
 * Loads everything once per request (static cache) instead of querying per
 * grid column/calendar - the yearly overview calls this once per day column,
 * which would otherwise mean a couple hundred queries per page load.
 */
class CalendarEntryDisplayService
{
    /** @var array<string, CalendarEntry[]>|null */
    private static ?array $entriesByDate = null;

    /** @var Calendar[]|null */
    private static ?array $calendars = null;

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
        $this->warm();

        return self::$entriesByDate[$date->format('Y-m-d')] ?? [];
    }

    /**
     * @return Calendar[]
     */
    public function getAllCalendars(): array
    {
        $this->warm();

        return self::$calendars;
    }

    private function warm(): void
    {
        if (null !== self::$entriesByDate) {
            return;
        }

        self::$calendars = $this->calendarRepo->findAllOrdered();

        self::$entriesByDate = [];
        foreach ($this->entryRepo->findBy([]) as $entry) {
            self::$entriesByDate[$entry->getDate()->format('Y-m-d')][] = $entry;
        }
    }

    /**
     * Reset the request-level cache. Only needed in long-running processes
     * (tests, workers) where the same PHP process serves multiple requests.
     */
    public static function resetCache(): void
    {
        self::$entriesByDate = null;
        self::$calendars = null;
    }
}
