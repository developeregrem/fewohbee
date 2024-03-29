<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CashJournal;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * CashJournalRepository.
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class CashJournalEntryRepository extends EntityRepository
{
    public function findAll(): array
    {
        return $this->findBy([], ['date' => 'ASC']);
    }

    public function findByFilter(CashJournal $journal, $search, $page = 1, $limit = 20)
    {
        $q = $this
            ->createQueryBuilder('cje')
            ->select('cje')
            ->where('cje.cashJournal = :journal and cje.remark LIKE :search')
            ->setParameter('search', '%'.$search.'%')
            ->setParameter('journal', $journal)
            ->addOrderBy('cje.date', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery();
        $paginator = new Paginator($q, $fetchJoinCollection = false);

        return $paginator;
    }

    public function getLastDocumentNumber($journal)
    {
        $q = $this
            ->createQueryBuilder('cje')
            ->select('MAX(cje.documentNumber)')
            ->leftJoin('cje.cashJournal', 'cj')
            ->where('cj.cashYear = :year')
            ->setParameter('year', $journal->getCashYear())
            ->getQuery();

        return $q->getSingleScalarResult();
    }

    public function getMinDocumentNumber($journal)
    {
        $q = $this
            ->createQueryBuilder('cje')
            ->select('MIN(cje.documentNumber)')
            ->where('cje.cashJournal = :journal')
            ->setParameter('journal', $journal)
            ->getQuery();

        return $q->getSingleScalarResult();
    }

    public function supportsClass($class)
    {
        return $this->getEntityName() === $class
        || is_subclass_of($class, $this->getEntityName());
    }
}
