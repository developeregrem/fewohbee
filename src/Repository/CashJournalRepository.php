<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * CashJournalRepository.
 */
class CashJournalRepository extends EntityRepository
{
    public function getJournalYears()
    {
        $q = $this
            ->createQueryBuilder('cj')
            ->select('cj.cashYear')
            ->addGroupBy('cj.cashYear')
            ->addOrderBy('cj.cashYear', 'DESC')
            ->getQuery();

        return $q->getResult();
    }

    public function findByFilter($search, $page = 1, $limit = 20)
    {
        $q = $this
            ->createQueryBuilder('cj')
            ->select('cj')
            ->where('cj.cashYear = :search')
            ->setParameter('search', $search)
            ->addOrderBy('cj.cashMonth', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery();
        $paginator = new Paginator($q, $fetchJoinCollection = false);

        return $paginator;
    }

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

    public function supportsClass($class)
    {
        return $this->getEntityName() === $class
        || is_subclass_of($class, $this->getEntityName());
    }
}
