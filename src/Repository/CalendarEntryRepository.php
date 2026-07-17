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
            ->orderBy('e.date', 'ASC')
            ->addOrderBy('e.title', 'ASC')
            ->getQuery()
            ->getResult();
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
     * All entries (any calendar, confirmed or not) within the given year,
     * with their calendar eager-loaded - backs the year-overview accent
     * lines/popovers via CalendarEntryDisplayService, scoped to the year
     * actually being displayed instead of loading the whole table.
     *
     * @return CalendarEntry[]
     */
    public function findForYear(int $year): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.calendar', 'c')
            ->addSelect('c')
            ->andWhere('e.date BETWEEN :from AND :to')
            ->setParameter('from', new \DateTimeImmutable($year.'-01-01'))
            ->setParameter('to', new \DateTimeImmutable($year.'-12-31'))
            ->getQuery()
            ->getResult();
    }
}
