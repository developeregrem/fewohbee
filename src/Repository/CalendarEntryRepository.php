<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Calendar;
use App\Entity\CalendarEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class CalendarEntryRepository extends ServiceEntityRepository
{
    /**
     * Orders entries within one day by the clock time they actually happen at.
     *
     * An entry has a start time, an end time, or neither. The closing day of a
     * multi-day entry carries only the end time (see CalendarEntry::$endTime),
     * and belongs at that hour rather than lumped in with the all-day entries
     * that a plain "ORDER BY e.time" would sort it among. All-day entries have
     * neither time, stay NULL and so sort first.
     *
     * Selected as a HIDDEN alias rather than written straight into ORDER BY:
     * DQL only accepts a plain field or an alias there, not an expression.
     * HIDDEN keeps it out of the result, so callers still get entities.
     */
    private const TIME_OF_DAY_SELECT = 'COALESCE(e.time, e.endTime) AS HIDDEN timeOfDay';
    private const TIME_OF_DAY_ORDER = 'timeOfDay';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarEntry::class);
    }

    /**
     * Entries across all calendars that still need their reminder
     * acknowledged, for today or tomorrow (shown the day before, stays
     * visible through the collection day itself if not yet confirmed).
     * Only calendars with requiresConfirmation are considered.
     *
     * @return CalendarEntry[]
     */
    public function findPendingReminders(): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.calendar', 'c')
            ->andWhere('c.requiresConfirmation = true')
            ->andWhere('e.date BETWEEN :today AND :tomorrow')
            ->andWhere('e.confirmedAt IS NULL')
            ->setParameter('today', new \DateTimeImmutable('today', new \DateTimeZone('UTC')))
            ->setParameter('tomorrow', new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC')))
            ->addSelect(self::TIME_OF_DAY_SELECT)
            ->orderBy('e.date', 'ASC')
            ->addOrderBy(self::TIME_OF_DAY_ORDER, 'ASC')
            ->addOrderBy('e.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Entry count per calendar, in one grouped query rather than one count
     * per calendar - the management list needs all of them at once.
     *
     * Calendars without a single entry produce no row and are simply absent
     * from the result; callers treat a missing key as zero.
     *
     * @return array<int, int> calendar id => number of entries
     */
    public function countGroupedByCalendar(): array
    {
        $counts = [];
        foreach ($this->createQueryBuilder('e')
            ->select('IDENTITY(e.calendar) AS calendarId, COUNT(e.id) AS entryCount')
            ->groupBy('e.calendar')
            ->getQuery()
            ->getScalarResult() as $row) {
            $counts[(int) $row['calendarId']] = (int) $row['entryCount'];
        }

        return $counts;
    }

    public function countPendingReminders(): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->join('e.calendar', 'c')
            ->andWhere('c.requiresConfirmation = true')
            ->andWhere('e.date BETWEEN :today AND :tomorrow')
            ->andWhere('e.confirmedAt IS NULL')
            ->setParameter('today', new \DateTimeImmutable('today', new \DateTimeZone('UTC')))
            ->setParameter('tomorrow', new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC')))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Every entry this calendar holds for one source event, oldest first -
     * backs the reconciliation in CalendarEntrySyncService, which needs the
     * whole set at once to tell a moved occurrence from a new one.
     *
     * @return CalendarEntry[]
     */
    public function findBySource(Calendar $calendar, string $sourceUid): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.calendar = :calendar')
            ->andWhere('e.sourceUid = :sourceUid')
            ->setParameter('calendar', $calendar)
            ->setParameter('sourceUid', $sourceUid)
            ->orderBy('e.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * How many entries a deleteUnconfirmedPast() call would remove - used to
     * show a specific count on the button before anything is deleted.
     */
    public function countUnconfirmedPast(Calendar $calendar): int
    {
        return (int) $this->unconfirmedPastQuery($calendar)
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Deletes this calendar's entries that are in the past and were never
     * confirmed - a manual cleanup so the database doesn't keep accumulating
     * rows now that sync() never prunes anything on its own (see
     * CalendarEntrySyncService). Confirmed entries are a historical record
     * and are never touched; neither is anything from today onwards, whose
     * reminder may still be acted on.
     *
     * @return int number of entries deleted
     */
    public function deleteUnconfirmedPast(Calendar $calendar): int
    {
        $ids = $this->unconfirmedPastQuery($calendar)
            ->select('e.id')
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $ids) {
            return 0;
        }

        // Two-step (select ids, then delete by id) rather than a single
        // DELETE ... WHERE id IN (SELECT ...) - MySQL rejects a subquery
        // that selects from the same table being deleted from.
        return (int) $this->createQueryBuilder('e')
            ->delete()
            ->andWhere('e.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    /** Shared filter behind countUnconfirmedPast()/deleteUnconfirmedPast(). */
    private function unconfirmedPastQuery(Calendar $calendar): QueryBuilder
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.calendar = :calendar')
            ->andWhere('e.confirmedAt IS NULL')
            ->andWhere('e.date < :today')
            ->setParameter('calendar', $calendar)
            ->setParameter('today', new \DateTimeImmutable('today'));
    }

    /**
     * All entries (any calendar, confirmed or not) that fall inside the given
     * period, with their calendar eager-loaded - backs the accent lines and
     * day popovers in the reservation overview.
     *
     * Ordered by calendar name so same-day entries arrive grouped per
     * calendar, which is what the popover separates with a rule.
     *
     * @return CalendarEntry[]
     */
    public function findForPeriod(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.calendar', 'c')
            ->addSelect('c')
            ->andWhere('e.date BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->addSelect(self::TIME_OF_DAY_SELECT)
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('e.date', 'ASC')
            ->addOrderBy(self::TIME_OF_DAY_ORDER, 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Entries of a single calendar inside the given period, date-ascending.
     *
     * @return CalendarEntry[]
     */
    public function findForCalendarAndPeriod(Calendar $calendar, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.calendar = :calendar')
            ->andWhere('e.date BETWEEN :from AND :to')
            ->setParameter('calendar', $calendar)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.date', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
