<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CalendarEntryViolation;
use App\Entity\CalendarEntry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Validation and persistence for manually created calendar entries.
 *
 * The counterpart to CalendarEntrySyncService, which does the same for entries
 * arriving from an ICS feed. Both split a period into one row per day and both
 * read the times through CalendarEntryTimeRules, so an entry the import writes
 * is one the edit form accepts, and the other way round.
 */
final class CalendarEntryService
{
    /**
     * Upper bound on a manually entered period. Matches
     * IcsEventSpanResolver::MAX_EVENT_SPAN_DAYS: a range creates one row per
     * day, and a typo in the end date should not fill the calendar for years.
     */
    public const MAX_RANGE_DAYS = 366;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalendarEntryTimeRules $timeRules,
    ) {
    }

    /**
     * Checks an entry that is being created, possibly over a period.
     *
     * $dateTo is the raw value of the unmapped "until" field; anything not
     * after the entry's own date means a single day, which is also what an
     * empty field means.
     *
     * @return list<CalendarEntryViolation> empty when the entry may be saved
     */
    public function validateRange(CalendarEntry $entry, ?\DateTimeImmutable $dateTo): array
    {
        $startDate = $entry->getDate();
        $endDate = $this->resolveEndDate($startDate, $dateTo);

        $violations = [];
        if ($startDate->diff($endDate)->days > self::MAX_RANGE_DAYS) {
            $violations[] = new CalendarEntryViolation('dateTo', 'calendar_entry.form.date_to_too_far', [
                '%max%' => (string) self::MAX_RANGE_DAYS,
            ]);
        }

        // Over a period the two times sit on different days, where any order
        // is legitimate - 18:00 on the first day, 09:00 on the last.
        if ($endDate == $startDate) {
            $violations = array_merge($violations, $this->validateTimes($entry));
        }

        return $violations;
    }

    /**
     * Checks an entry edited on its own, which is always a single day - so
     * both times belong to it and their order matters.
     *
     * @return list<CalendarEntryViolation> empty when the entry may be saved
     */
    public function validateSingle(CalendarEntry $entry): array
    {
        return $this->validateTimes($entry);
    }

    /**
     * Persists one entry per day of the period and flushes.
     *
     * $entry itself becomes the first day, so a form-bound entity keeps its
     * identity; the remaining days are copies. They all keep sourceUid null,
     * which is what marks them as manually created and keeps the ICS sync from
     * ever reconciling them.
     *
     * The form fills both times onto the bound entity. Over a period the end
     * time belongs to the closing day instead, and the days in between run all
     * day - the same split IcsEventSpanResolver makes.
     *
     * Call validateRange() first; this method assumes a valid period.
     *
     * @return int the number of entries created
     */
    public function createRange(CalendarEntry $entry, ?\DateTimeImmutable $dateTo): int
    {
        $startDate = $entry->getDate();
        $endDate = $this->resolveEndDate($startDate, $dateTo);

        // Read off the bound entity before the loop overwrites it: over a
        // period the end time belongs to the closing day, not to the first.
        // CalendarEntryTimeRules decides whether that day may carry it at all,
        // the same call the ICS import makes - otherwise a period ending at
        // 00:00 would store a lone "- 00:00" the edit form then rejects.
        $endTime = $this->timeRules->endTimeForClosingDay($entry->getEndTime(), $endDate == $startDate);
        if ($endDate != $startDate) {
            $entry->setEndTime(null);
        }

        $created = 0;
        for ($day = $startDate; $day <= $endDate; $day = $day->modify('+1 day')) {
            $dayEntry = $day == $startDate
                ? $entry
                : (new CalendarEntry())->setCalendar($entry->getCalendar())->setTitle($entry->getTitle());
            $dayEntry->setDate($day);
            if ($day != $startDate && $day == $endDate) {
                $dayEntry->setEndTime($endTime);
            }
            $this->em->persist($dayEntry);
            ++$created;
        }
        $this->em->flush();

        return $created;
    }

    /** @return list<CalendarEntryViolation> */
    private function validateTimes(CalendarEntry $entry): array
    {
        if ($this->timeRules->isValidRange($entry->getTime(), $entry->getEndTime())) {
            return [];
        }

        // An end of 00:00 is the one case that fails for a different reason:
        // it is a valid end of day, but only next to a start time.
        $messageKey = $this->timeRules->endsAtMidnight($entry->getEndTime())
            ? 'calendar_entry.form.end_time_midnight_without_start'
            : 'calendar_entry.form.end_time_before_start';

        return [new CalendarEntryViolation('endTime', $messageKey)];
    }

    private function resolveEndDate(\DateTimeImmutable $startDate, ?\DateTimeImmutable $dateTo): \DateTimeImmutable
    {
        return (null !== $dateTo && $dateTo > $startDate) ? $dateTo : $startDate;
    }
}
