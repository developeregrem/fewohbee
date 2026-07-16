<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CalendarEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
     * Confirmed entries for the given year, across all calendars that
     * require confirmation (in practice the waste calendar) - used by the
     * Facility overview under Operations to show who put a bin out and
     * when.
     *
     * @return CalendarEntry[]
     */
    public function findConfirmedForYear(int $year): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.calendar', 'c')
            ->addSelect('c')
            ->leftJoin('e.confirmedBy', 'u')
            ->addSelect('u')
            ->andWhere('c.requiresConfirmation = true')
            ->andWhere('e.confirmedAt IS NOT NULL')
            ->andWhere('e.date BETWEEN :from AND :to')
            ->setParameter('from', new \DateTimeImmutable($year.'-01-01'))
            ->setParameter('to', new \DateTimeImmutable($year.'-12-31'))
            ->orderBy('e.date', 'DESC')
            ->addOrderBy('e.confirmedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * How many entries a deleteUnconfirmedForYear() call for this year
     * would remove - used to show a specific count in the confirmation
     * prompt before actually deleting anything.
     */
    public function countUnconfirmedForYear(int $year): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.confirmedAt IS NULL')
            ->andWhere('e.date BETWEEN :from AND :to')
            ->setParameter('from', new \DateTimeImmutable($year.'-01-01'))
            ->setParameter('to', new \DateTimeImmutable($year.'-12-31'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Deletes entries with no confirmedAt, across ALL calendars, within the
     * given year - a manual cleanup for years that are over, since sync()
     * never prunes anything on its own anymore (see
     * CalendarEntrySyncService). Generic on purpose: calendars that don't
     * require confirmation never have confirmedAt set at all, so their
     * entries are just as eligible here as a genuinely-missed reminder on a
     * confirmation-requiring calendar - "was this ever confirmed" is the
     * only thing that determines whether an entry is disposable history or
     * an audit record worth keeping forever, not which calendar it's on.
     *
     * @return int number of entries deleted
     */
    public function deleteUnconfirmedForYear(int $year): int
    {
        $ids = $this->createQueryBuilder('e')
            ->select('e.id')
            ->andWhere('e.confirmedAt IS NULL')
            ->andWhere('e.date BETWEEN :from AND :to')
            ->setParameter('from', new \DateTimeImmutable($year.'-01-01'))
            ->setParameter('to', new \DateTimeImmutable($year.'-12-31'))
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

    /**
     * Full year range with at least one entry across ANY calendar, newest
     * first - used to populate the year filter for the unconfirmed-entry
     * cleanup on the calendar management page.
     *
     * @return int[]
     */
    public function findDistinctYears(): array
    {
        $minDate = $this->createQueryBuilder('e')
            ->select('MIN(e.date)')
            ->getQuery()
            ->getSingleScalarResult();

        $maxDate = $this->createQueryBuilder('e')
            ->select('MAX(e.date)')
            ->getQuery()
            ->getSingleScalarResult();

        if (null === $minDate || null === $maxDate) {
            return [];
        }

        $minYear = (int) (new \DateTimeImmutable($minDate))->format('Y');
        $maxYear = (int) (new \DateTimeImmutable($maxDate))->format('Y');

        return range($maxYear, $minYear);
    }

    /**
     * Full year range with at least one confirmed entry, newest first.
     * Uses MIN/MAX (standard DQL) rather than YEAR(), which isn't a
     * built-in DQL function in this project.
     *
     * @return int[]
     */
    public function findDistinctConfirmedYears(): array
    {
        $minDate = $this->createQueryBuilder('e')
            ->select('MIN(e.date)')
            ->join('e.calendar', 'c')
            ->andWhere('c.requiresConfirmation = true')
            ->andWhere('e.confirmedAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        $maxDate = $this->createQueryBuilder('e')
            ->select('MAX(e.date)')
            ->join('e.calendar', 'c')
            ->andWhere('c.requiresConfirmation = true')
            ->andWhere('e.confirmedAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        if (null === $minDate || null === $maxDate) {
            return [];
        }

        $minYear = (int) (new \DateTimeImmutable($minDate))->format('Y');
        $maxYear = (int) (new \DateTimeImmutable($maxDate))->format('Y');

        return range($maxYear, $minYear);
    }
}
