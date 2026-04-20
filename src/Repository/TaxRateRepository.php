<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TaxRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class TaxRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaxRate::class);
    }

    private function applyPresetScope(QueryBuilder $qb, ?string $preset, string $alias = 't'): QueryBuilder
    {
        if (null === $preset) {
            return $qb->andWhere($alias.'.chartPreset IS NULL');
        }

        return $qb
            ->andWhere($alias.'.chartPreset = :preset OR '.$alias.'.chartPreset IS NULL')
            ->setParameter('preset', $preset);
    }

    /**
     * @return TaxRate[]
     */
    public function findAllOrdered(?string $preset = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.sortOrder', 'ASC')
            ->addOrderBy('t.rate', 'ASC');

        if (null !== $preset) {
            $this->applyPresetScope($qb, $preset);
        }

        return $qb->getQuery()->getResult();
    }

    public function findByRate(float $rate, ?\DateTimeInterface $date = null, ?string $preset = null): ?TaxRate
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.rate = :rate')
            ->setParameter('rate', number_format($rate, 2, '.', ''));

        if (null !== $date) {
            $qb->andWhere('t.validFrom IS NULL OR t.validFrom <= :date')
               ->andWhere('t.validTo IS NULL OR t.validTo >= :date')
               ->setParameter('date', $date);
        }

        if (null !== $preset) {
            $this->applyPresetScope($qb, $preset);
            $qb->orderBy('t.chartPreset', 'DESC');
        }

        return $qb
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findDefault(?string $preset = null): ?TaxRate
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.isDefault = true')
            ->setMaxResults(1);

        if (null !== $preset) {
            $this->applyPresetScope($qb, $preset);
            $qb->orderBy('t.chartPreset', 'DESC');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function createValidAtQueryBuilder(\DateTimeInterface $date, ?string $preset = null): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.validFrom IS NULL OR t.validFrom <= :date')
            ->andWhere('t.validTo IS NULL OR t.validTo >= :date')
            ->setParameter('date', $date)
            ->orderBy('t.sortOrder', 'ASC')
            ->addOrderBy('t.rate', 'ASC');

        if (null !== $preset) {
            $this->applyPresetScope($qb, $preset);
        }

        return $qb;
    }

    /**
     * @return TaxRate[]
     */
    public function findValidAt(\DateTimeInterface $date, ?string $preset = null): array
    {
        return $this->createValidAtQueryBuilder($date, $preset)
            ->getQuery()
            ->getResult();
    }
}
