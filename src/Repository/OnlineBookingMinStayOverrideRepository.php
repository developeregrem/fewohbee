<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OnlineBookingMinStayOverride;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OnlineBookingMinStayOverrideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OnlineBookingMinStayOverride::class);
    }

    /**
     * Find all overrides whose date range covers the given arrival date (start <= arrival)
     * and whose end date is after the arrival date (end > arrival, since endDate = departure date).
     *
     * @return OnlineBookingMinStayOverride[]
     */
    public function findActiveForArrival(\DateTimeImmutable $arrivalDate): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.startDate <= :arrival')
            ->andWhere('o.endDate > :arrival')
            ->setParameter('arrival', $arrivalDate)
            ->getQuery()
            ->getResult();
    }
}
