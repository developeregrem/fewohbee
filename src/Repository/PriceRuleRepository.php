<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PriceRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PriceRule>
 */
class PriceRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PriceRule::class);
    }

    /**
     * All rules including disabled ones, for the settings page.
     *
     * @return list<PriceRule>
     */
    public function findForSettings(): array
    {
        return $this->createQueryBuilder('r')->addSelect('s', 'c')
            ->leftJoin('r.subsidiaries', 's')
            ->leftJoin('r.categories', 'c')
            ->orderBy('r.name', 'ASC')->addOrderBy('r.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * The enabled rules with their scopes, loaded in one query for all pricing in a request.
     *
     * @return list<PriceRule>
     */
    public function findEnabled(): array
    {
        return $this->createQueryBuilder('r')->addSelect('s', 'c')
            ->leftJoin('r.subsidiaries', 's')
            ->leftJoin('r.categories', 'c')
            ->where('r.enabled = true')
            ->orderBy('r.id', 'ASC')
            ->getQuery()->getResult();
    }
}
