<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subsidiary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SubsidiaryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subsidiary::class);
    }

    /**
     * Load all subsidiary IDs for configuration resolution in Online Booking.
     *
     * @return int[]
     */
    public function loadAllIds(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.id')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Return only existing subsidiary IDs from a given selection.
     *
     * @param int[] $ids
     * @return int[]
     */
    public function loadExistingIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->createQueryBuilder('s')
            ->select('s.id')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Invoice number patterns configured on branches, without the global default.
     *
     * @return list<string>
     */
    public function findConfiguredPatterns(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.invoiceNumberPattern')
            ->where('s.invoiceNumberPattern IS NOT NULL')
            ->andWhere("s.invoiceNumberPattern <> ''")
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => (string) $row['invoiceNumberPattern'], $rows);
    }

    /**
     * All branches in the order the API and the overview present them.
     *
     * @return list<Subsidiary>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.name', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Branches that define their own number range, keyed by nothing in particular —
     * used by the bank import settings screen to label each pattern with its branch.
     *
     * @return list<Subsidiary>
     */
    public function findWithInvoiceNumberPattern(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.invoiceNumberPattern IS NOT NULL')
            ->andWhere("s.invoiceNumberPattern <> ''")
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
