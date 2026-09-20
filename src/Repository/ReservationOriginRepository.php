<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ReservationOrigin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ReservationOrigin> */
final class ReservationOriginRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReservationOrigin::class);
    }

    public function hasOtaFees(): bool
    {
        return (int) $this->createQueryBuilder('origin')
            ->select('COUNT(origin.id)')
            ->where('origin.commissionPercent IS NOT NULL')
            ->orWhere('origin.paymentFeePercent IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
