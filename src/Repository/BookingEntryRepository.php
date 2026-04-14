<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BookingBatch;
use App\Entity\BookingEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BookingEntry>
 */
class BookingEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookingEntry::class);
    }

    public function findByBatch(BookingBatch $batch, string $search = '', int $page = 1, int $limit = 20, bool $cashOnly = false): Paginator
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.bookingBatch = :batch')
            ->setParameter('batch', $batch)
            ->orderBy('e.date', 'ASC')
            ->addOrderBy('e.documentNumber', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($cashOnly) {
            $qb->leftJoin('e.debitAccount', 'da')
                ->leftJoin('e.creditAccount', 'ca')
                ->andWhere('da.isCashAccount = true OR ca.isCashAccount = true');
        }

        if ('' !== $search) {
            $qb->andWhere('e.remark LIKE :search OR e.invoiceNumber LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        return new Paginator($qb->getQuery(), false);
    }

    /**
     * Returns entries involving the cash account for a given batch.
     *
     * @return BookingEntry[]
     */
    public function findCashEntriesByBatch(BookingBatch $batch): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.bookingBatch', 'b')
            ->leftJoin('e.debitAccount', 'da')
            ->leftJoin('e.creditAccount', 'ca')
            ->where('e.bookingBatch = :batch')
            ->andWhere('da.isCashAccount = true OR ca.isCashAccount = true')
            ->setParameter('batch', $batch)
            ->orderBy('e.date', 'ASC')
            ->addOrderBy('e.documentNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the highest document number used in the given batch's year, or 0 if no entries exist.
     */
    public function getLastDocumentNumber(BookingBatch $batch): int
    {
        $result = $this->createQueryBuilder('e')
            ->select('MAX(e.documentNumber)')
            ->join('e.bookingBatch', 'b')
            ->where('b.year = :year')
            ->setParameter('year', $batch->getYear())
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Returns the lowest document number used in the given batch's year, or 1 if no entries exist.
     */
    public function getMinDocumentNumber(BookingBatch $batch): int
    {
        $result = $this->createQueryBuilder('e')
            ->select('MIN(e.documentNumber)')
            ->where('e.bookingBatch = :batch')
            ->setParameter('batch', $batch)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 1);
    }
}
