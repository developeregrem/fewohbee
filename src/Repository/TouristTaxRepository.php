<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subsidiary;
use App\Entity\TouristTax;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TouristTax>
 */
class TouristTaxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TouristTax::class);
    }

    /**
     * Active taxes for the given subsidiary whose validity range overlaps the
     * given [start, end] date range (inclusive on both ends). Per-night
     * filtering is the caller's responsibility (TouristTax::isValidOn).
     *
     * @return TouristTax[]
     */
    public function findActiveForSubsidiaryInRange(?Subsidiary $subsidiary, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.subsidiaries', 's')
            ->andWhere('t.active = :active')
            ->andWhere('t.validFrom IS NULL OR t.validFrom <= :end')
            ->andWhere('t.validTo IS NULL OR t.validTo >= :start')
            ->setParameter('active', true)
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->orderBy('t.sortOrder', 'ASC')
            ->addOrderBy('t.id', 'ASC');

        if (null !== $subsidiary) {
            $qb->andWhere('s.id IS NULL OR s.id = :sid')
                ->setParameter('sid', $subsidiary->getId());
        }

        return $qb->getQuery()->getResult();
    }

    public function hasActiveForSubsidiary(?Subsidiary $subsidiary): bool
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->leftJoin('t.subsidiaries', 's')
            ->andWhere('t.active = :active')
            ->setParameter('active', true);

        if (null !== $subsidiary) {
            $qb->andWhere('s.id IS NULL OR s.id = :sid')
                ->setParameter('sid', $subsidiary->getId());
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /** @return TouristTax[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.sortOrder', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
