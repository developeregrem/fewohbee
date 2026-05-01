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

    public function findByBatch(BookingBatch $batch, string $search = '', int $page = 1, int $limit = 20, string $mode = 'all'): Paginator
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.bookingBatch = :batch')
            ->setParameter('batch', $batch)
            ->orderBy('e.date', 'ASC')
            ->addOrderBy('e.documentNumber', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ('cashbook' === $mode) {
            $qb->leftJoin('e.debitAccount', 'da')
                ->leftJoin('e.creditAccount', 'ca')
                ->andWhere('da.isCashAccount = true OR ca.isCashAccount = true')
                ->andWhere('e.sourceType IS NULL OR e.sourceType != :opening')
                ->setParameter('opening', BookingEntry::SOURCE_OPENING_BALANCE);
        } elseif ('bankbook' === $mode) {
            $qb->leftJoin('e.debitAccount', 'da')
                ->leftJoin('e.creditAccount', 'ca')
                ->andWhere('da.isBankAccount = true OR ca.isBankAccount = true')
                ->andWhere('e.sourceType IS NULL OR e.sourceType != :opening')
                ->setParameter('opening', BookingEntry::SOURCE_OPENING_BALANCE);
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
     * Net balance of bank-account movements in batches earlier than the given one.
     * Used as the opening balance for the bank view.
     */
    /**
     * Net bank balance at the start of the given batch, computed from the current year only.
     *
     * Convention: every year must be anchored by an opening-balance booking, so looking further
     * back than the current year is never needed. Returns: sum of bank movements in this year's
     * batches before the given one + the opening-balance bank entry of this year (even if it sits
     * in the given batch — January).
     */
    public function getBankOpeningBalance(BookingBatch $batch): float
    {
        $result = $this->createQueryBuilder('e')
            ->select('
                SUM(CASE WHEN da.isBankAccount = true THEN ABS(e.amount) ELSE 0 END)
                - SUM(CASE WHEN ca.isBankAccount = true THEN ABS(e.amount) ELSE 0 END) AS balance
            ')
            ->join('e.bookingBatch', 'b')
            ->leftJoin('e.debitAccount', 'da')
            ->leftJoin('e.creditAccount', 'ca')
            ->where('b.year = :year AND (b.month < :month OR e.sourceType = :opening)')
            ->setParameter('year', $batch->getYear())
            ->setParameter('month', $batch->getMonth())
            ->setParameter('opening', BookingEntry::SOURCE_OPENING_BALANCE)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /**
     * Net bank-account delta within the given batch (incomes minus expenses).
     */
    public function getBankBatchDelta(BookingBatch $batch): float
    {
        $result = $this->createQueryBuilder('e')
            ->select('
                SUM(CASE WHEN da.isBankAccount = true THEN ABS(e.amount) ELSE 0 END)
                - SUM(CASE WHEN ca.isBankAccount = true THEN ABS(e.amount) ELSE 0 END) AS balance
            ')
            ->leftJoin('e.debitAccount', 'da')
            ->leftJoin('e.creditAccount', 'ca')
            ->where('e.bookingBatch = :batch')
            ->andWhere('e.sourceType IS NULL OR e.sourceType != :opening')
            ->setParameter('batch', $batch)
            ->setParameter('opening', BookingEntry::SOURCE_OPENING_BALANCE)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /**
     * Net cash balance at the start of the given batch (this year's opening + prior months' deltas).
     */
    public function getCashOpeningBalance(BookingBatch $batch): float
    {
        $result = $this->createQueryBuilder('e')
            ->select('
                SUM(CASE WHEN da.isCashAccount = true THEN ABS(e.amount) ELSE 0 END)
                - SUM(CASE WHEN ca.isCashAccount = true THEN ABS(e.amount) ELSE 0 END) AS balance
            ')
            ->join('e.bookingBatch', 'b')
            ->leftJoin('e.debitAccount', 'da')
            ->leftJoin('e.creditAccount', 'ca')
            ->where('b.year = :year AND (b.month < :month OR e.sourceType = :opening)')
            ->setParameter('year', $batch->getYear())
            ->setParameter('month', $batch->getMonth())
            ->setParameter('opening', BookingEntry::SOURCE_OPENING_BALANCE)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /**
     * Net cash delta within the given batch, excluding opening-balance entries.
     */
    public function getCashBatchDelta(BookingBatch $batch): float
    {
        $result = $this->createQueryBuilder('e')
            ->select('
                SUM(CASE WHEN da.isCashAccount = true THEN ABS(e.amount) ELSE 0 END)
                - SUM(CASE WHEN ca.isCashAccount = true THEN ABS(e.amount) ELSE 0 END) AS balance
            ')
            ->leftJoin('e.debitAccount', 'da')
            ->leftJoin('e.creditAccount', 'ca')
            ->where('e.bookingBatch = :batch')
            ->andWhere('e.sourceType IS NULL OR e.sourceType != :opening')
            ->setParameter('batch', $batch)
            ->setParameter('opening', BookingEntry::SOURCE_OPENING_BALANCE)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /**
     * Per-month cash deltas (opening-balance entries excluded) for the given year.
     *
     * @return array<int, float> month => delta
     */
    public function getCashDeltasByMonth(int $year): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('b.month AS month, '
                .'SUM(CASE WHEN da.isCashAccount = true THEN ABS(e.amount) ELSE 0 END) '
                .'- SUM(CASE WHEN ca.isCashAccount = true THEN ABS(e.amount) ELSE 0 END) AS delta')
            ->join('e.bookingBatch', 'b')
            ->leftJoin('e.debitAccount', 'da')
            ->leftJoin('e.creditAccount', 'ca')
            ->where('b.year = :year')
            ->andWhere('e.sourceType IS NULL OR e.sourceType != :opening')
            ->setParameter('year', $year)
            ->setParameter('opening', BookingEntry::SOURCE_OPENING_BALANCE)
            ->groupBy('b.month')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['month']] = (float) ($row['delta'] ?? 0);
        }

        return $out;
    }

    /**
     * Cash opening-balance entry amount for the given year (0 if none).
     */
    public function getCashOpeningForYear(int $year): float
    {
        $result = $this->createQueryBuilder('e')
            ->select('SUM(ABS(e.amount)) AS balance')
            ->join('e.bookingBatch', 'b')
            ->leftJoin('e.debitAccount', 'da')
            ->where('b.year = :year')
            ->andWhere('e.sourceType = :opening')
            ->andWhere('da.isCashAccount = true')
            ->setParameter('year', $year)
            ->setParameter('opening', BookingEntry::SOURCE_OPENING_BALANCE)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
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
     * Returns normal journal entries of a year in the order used for gapless document numbering.
     *
     * Opening-balance entries keep their technical document number 0 and are intentionally excluded.
     *
     * @return BookingEntry[]
     */
    public function findEntriesForDocumentNumbering(int $year): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.bookingBatch', 'b')
            ->where('b.year = :year')
            ->andWhere('e.sourceType IS NULL OR e.sourceType != :opening')
            ->setParameter('year', $year)
            ->setParameter('opening', BookingEntry::SOURCE_OPENING_BALANCE)
            ->orderBy('e.date', 'ASC')
            ->addOrderBy('e.documentNumber', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the opening balance entry for the given year and asset account (cash or bank),
     * or null if none exists.
     */
    public function findOpeningBalanceEntry(int $year, \App\Entity\AccountingAccount $assetAccount): ?BookingEntry
    {
        return $this->createQueryBuilder('e')
            ->join('e.bookingBatch', 'b')
            ->where('b.year = :year')
            ->andWhere('e.sourceType = :source')
            ->andWhere('e.debitAccount = :asset')
            ->setParameter('year', $year)
            ->setParameter('source', BookingEntry::SOURCE_OPENING_BALANCE)
            ->setParameter('asset', $assetAccount)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
