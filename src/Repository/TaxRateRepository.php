<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TaxRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TaxRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaxRate::class);
    }

    /**
     * @return TaxRate[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.sortOrder', 'ASC')
            ->addOrderBy('t.rate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByRate(float $rate, ?\DateTimeInterface $date = null): ?TaxRate
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.rate = :rate')
            ->setParameter('rate', number_format($rate, 2, '.', ''));

        if (null !== $date) {
            $qb->andWhere('t.validFrom IS NULL OR t.validFrom <= :date')
               ->andWhere('t.validTo IS NULL OR t.validTo >= :date')
               ->setParameter('date', $date);
        }

        return $qb
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findDefault(): ?TaxRate
    {
        return $this->findOneBy(['isDefault' => true]);
    }

    public function createValidAtQueryBuilder(\DateTimeInterface $date): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->where('t.validFrom IS NULL OR t.validFrom <= :date')
            ->andWhere('t.validTo IS NULL OR t.validTo >= :date')
            ->setParameter('date', $date)
            ->orderBy('t.sortOrder', 'ASC')
            ->addOrderBy('t.rate', 'ASC');
    }

    /**
     * @return TaxRate[]
     */
    public function findValidAt(\DateTimeInterface $date): array
    {
        return $this->createValidAtQueryBuilder($date)
            ->getQuery()
            ->getResult();
    }
}
