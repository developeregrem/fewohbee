<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The single place that knows what a calendar entry's start and end time mean.
 *
 * A CalendarEntry stores one row per day (see CalendarEntry::$date), so both
 * times are plain clock times without a date of their own. That makes one case
 * ambiguous - an end of 00:00 - and every consumer has to read it the same way
 * or they contradict each other: the ICS import would write entries the edit
 * form then refuses to save.
 *
 * The rule: an end time of 00:00 is midnight at the *end* of the day, never at
 * its beginning. It is therefore later than any start time, not earlier.
 */
final class CalendarEntryTimeRules
{
    /**
     * Whether an end time denotes midnight closing the day.
     *
     * Compared on H:i rather than the full H:i:s because the form's TimeType
     * submits minute precision while a feed may carry seconds.
     */
    public function endsAtMidnight(?\DateTimeImmutable $end): bool
    {
        return null !== $end && '00:00' === $end->format('H:i');
    }

    /**
     * Whether a start/end pair belonging to one day is usable.
     *
     * Valid combinations, all of which occur in practice:
     * - neither time: an all-day entry, the default and the only kind that
     *   existed before times were introduced
     * - start only: an entry that has begun but says nothing about its end
     * - end only: the closing day of a multi-day entry, whose start sits on
     *   the first day
     * - both, end after start: the ordinary timed entry
     * - both, end exactly 00:00: an entry running until midnight
     *
     * The one rejected combination besides an end at or before the start is an
     * end of 00:00 standing alone: "ends at the end of the day" says nothing
     * without a start, and such an entry is an all-day one.
     */
    /**
     * The end time to store on the day an entry finishes on.
     *
     * A midnight end means the entry runs to the end of that day. On a day
     * that has no start time of its own - any closing day other than the one
     * the entry starts on - that is exactly what an all-day entry already
     * says, so nothing is stored: a lone "- 00:00" would read as ending at
     * the day's *beginning*, and isValidRange() rejects it for that reason.
     * Storing it anyway would produce an entry the edit form then refuses to
     * save.
     *
     * @param bool $isStartDay whether the closing day is also the day the entry starts on
     */
    public function endTimeForClosingDay(?\DateTimeImmutable $endTime, bool $isStartDay): ?\DateTimeImmutable
    {
        if (!$isStartDay && $this->endsAtMidnight($endTime)) {
            return null;
        }

        return $endTime;
    }

    public function isValidRange(?\DateTimeImmutable $start, ?\DateTimeImmutable $end): bool
    {
        if ($this->endsAtMidnight($end)) {
            return null !== $start;
        }

        if (null === $start || null === $end) {
            return true;
        }

        return $end->format('H:i:s') > $start->format('H:i:s');
    }
}
