<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GuestCategory;
use App\Entity\GuestCategoryModifier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuestCategoryModifier>
 */
class GuestCategoryModifierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuestCategoryModifier::class);
    }

    /**
     * Returns active modifiers valid on the given date. Modifier visibility is
     * inherited from its GuestCategory's subsidiary mapping; no separate scope
     * lives on the modifier itself.
     *
     * @return GuestCategoryModifier[]
     */
    public function findActiveOn(\DateTimeInterface $date): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.active = :active')
            ->andWhere('m.validFrom IS NULL OR m.validFrom <= :date')
            ->andWhere('m.validTo IS NULL OR m.validTo >= :date')
            ->setParameter('active', true)
            ->setParameter('date', $date->format('Y-m-d'))
            ->orderBy('m.sortOrder', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the first applicable modifier for the given category on the given date.
     */
    public function findApplicable(GuestCategory $category, \DateTimeInterface $date): ?GuestCategoryModifier
    {
        foreach ($this->findActiveOn($date) as $mod) {
            if ($mod->getCategory()?->getId() === $category->getId()) {
                return $mod;
            }
        }

        return null;
    }

    /** @return GuestCategoryModifier[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.category', 'c')->addSelect('c')
            ->orderBy('c.sortOrder', 'ASC')
            ->addOrderBy('m.sortOrder', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
