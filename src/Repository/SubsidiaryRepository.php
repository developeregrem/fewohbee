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
}
