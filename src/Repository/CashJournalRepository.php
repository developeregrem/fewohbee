<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * CashJournalRepository.
 *
 * @extends EntityRepository<\App\Entity\CashJournal>
 */
class CashJournalRepository extends EntityRepository
{
    /** @return list<array{cashYear: int}> Distinct journal years, newest first */
    public function getJournalYears()
    {
        $q = $this
            ->createQueryBuilder('cj')
            ->select('cj.cashYear')
            ->addGroupBy('cj.cashYear')
            ->addOrderBy('cj.cashYear', \SortDirection::Descending)
            ->getQuery();

        return $q->getResult();
    }

    /**
     * @param int|string $search Calendar year; this is an equality filter, not a text search
     * @param int|string $page Integer page number, including query-string values
     * @param int $limit
     *
     * @return Paginator<\App\Entity\CashJournal>
     */
    public function findByFilter($search, $page = 1, $limit = 20)
    {
        $q = $this
            ->createQueryBuilder('cj')
            ->select('cj')
            ->where('cj.cashYear = :search')
            ->setParameter('search', $search)
            ->addOrderBy('cj.cashMonth', \SortDirection::Descending)
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery();
        $paginator = new Paginator($q, $fetchJoinCollection = false);

        return $paginator;
    }

    /** @return \App\Entity\CashJournal|null Journal for the latest year/month, or null when none exists */
    public function getYoungestJournal()
    {
        $q = $this
            ->createQueryBuilder('cj')
            ->select('MAX(cj.cashYear)')
            ->getQuery();

        try {
            $res = $q->getSingleScalarResult();
        } catch (NoResultException $e) {
            return null;
        }

        $maxMonthQ = '(SELECT MAX(sub1.cashMonth) FROM App\Entity\CashJournal sub1 WHERE sub1.cashYear=:year)';
        $q = $this
            ->createQueryBuilder('cj')
            ->select('cj')
            ->where('cj.cashYear=:year AND cj.cashMonth='.$maxMonthQ.'')
            ->setParameter('year', $res)
            ->getQuery();

        try {
            return $q->getSingleResult();
        } catch (NoResultException $e) {
            return null;
        }
    }

    public function findByYearAndMonth(int $year, int $month): ?object
    {
        $q = $this
            ->createQueryBuilder('cj')
            ->select('cj')
            ->where('cj.cashYear = :year AND cj.cashMonth = :month')
            ->setParameter('year', $year)
            ->setParameter('month', $month)
            ->getQuery();

        try {
            return $q->getSingleResult();
        } catch (NoResultException $e) {
            return null;
        }
    }

    /**
     * @param string $class Class name; unknown names are not supported
     *
     * @return bool
     */
    public function supportsClass($class)
    {
        return $this->getEntityName() === $class
        || is_subclass_of($class, $this->getEntityName());
    }
}
