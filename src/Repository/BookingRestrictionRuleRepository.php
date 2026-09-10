<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BookingRestrictionRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Loads rule windows with their category scopes in one query, shared by the settings page
 * and the booking checks.
 *
 * @extends ServiceEntityRepository<BookingRestrictionRule>
 */
class BookingRestrictionRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookingRestrictionRule::class);
    }

    /**
     * All rules including disabled ones, for the settings lists.
     *
     * @return list<BookingRestrictionRule>
     */
    public function findForSettings(): array
    {
        return $this->createQueryBuilder('r')->addSelect('c')
            ->leftJoin('r.categories', 'c')
            ->orderBy('r.startDate', 'ASC')->addOrderBy('r.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Loads every rule that can affect a half-open date window, so callers never need a
     * query per room or per night. Unlimited rules have no dates and always match.
     *
     * @return list<BookingRestrictionRule>
     */
    public function findActiveForPeriod(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('r')->addSelect('c')
            ->leftJoin('r.categories', 'c')
            ->where('r.enabled = true')
            ->andWhere('r.startDate IS NULL OR r.startDate < :end')
            ->andWhere('r.endDate IS NULL OR r.endDate > :start')
            ->setParameter('start', $start)->setParameter('end', $end)
            ->orderBy('r.id', 'ASC')->getQuery()->getResult();
    }
}
