<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PricePeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method PricePeriod|null find($id, $lockMode = null, $lockVersion = null)
 * @method PricePeriod|null findOneBy(array $criteria, array $orderBy = null)
 * @method PricePeriod[]    findAll()
 * @method PricePeriod[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PricePeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PricePeriod::class);
    }

    /**
     * Periods of the given price rows that share at least one night with $start..$end (inclusive).
     *
     * @param list<int> $priceIds
     *
     * @return list<PricePeriod>
     */
    public function findOverlappingForPrices(array $priceIds, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        if ([] === $priceIds) {
            return [];
        }

        /** @var list<PricePeriod> $periods */
        $periods = $this->createQueryBuilder('pp')
            ->andWhere('pp.price IN (:prices)')
            ->andWhere('pp.start <= :end AND pp.end >= :start')
            ->setParameter('prices', $priceIds)
            ->setParameter('start', $start, Types::DATE_IMMUTABLE)
            ->setParameter('end', $end, Types::DATE_IMMUTABLE)
            ->orderBy('pp.start', 'ASC')
            ->getQuery()
            ->getResult();

        return $periods;
    }
}
