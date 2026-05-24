<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\GuestStatisticalGroup;
use App\Entity\GuestCategory;
use App\Entity\Subsidiary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuestCategory>
 */
class GuestCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuestCategory::class);
    }

    public function findBySystemCode(string $systemCode): ?GuestCategory
    {
        return $this->findOneBy(['systemCode' => $systemCode]);
    }

    /**
     * @return GuestCategory[]
     */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('gc')
            ->where('gc.active = :active')
            ->setParameter('active', true)
            ->orderBy('gc.sortOrder', 'ASC')
            ->addOrderBy('gc.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Active categories that are eligible for an apartment-rate modifier.
     * Excludes:
     *  - the ADULT statistical group: it is the unmodified base reference
     *    (PriceService skips it).
     *  - categories with isCountedInOccupancy=false: they are not part of
     *    `persons` and therefore never billed by the apartment line — a
     *    modifier on them would never take effect (InvoiceService skips it).
     *
     * @return GuestCategory[]
     */
    public function findActiveNonAdultOrdered(): array
    {
        return $this->createQueryBuilder('gc')
            ->where('gc.active = :active')
            ->andWhere('gc.statisticalGroup <> :adult')
            ->andWhere('gc.isCountedInOccupancy = :counted')
            ->setParameter('active', true)
            ->setParameter('adult', GuestStatisticalGroup::ADULT->value)
            ->setParameter('counted', true)
            ->orderBy('gc.sortOrder', 'ASC')
            ->addOrderBy('gc.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns active categories available for the given subsidiary.
     * Categories without any subsidiary mapping are considered globally available.
     *
     * @return GuestCategory[]
     */
    public function findActiveForSubsidiary(?Subsidiary $subsidiary): array
    {
        $qb = $this->createQueryBuilder('gc')
            ->leftJoin('gc.subsidiaries', 's')
            ->where('gc.active = :active')
            ->setParameter('active', true)
            ->orderBy('gc.sortOrder', 'ASC')
            ->addOrderBy('gc.id', 'ASC');

        if (null !== $subsidiary) {
            $qb->andWhere('s.id IS NULL OR s.id = :sid')
                ->setParameter('sid', $subsidiary->getId());
        }

        return $qb->getQuery()->getResult();
    }

    public function findDefaultAdult(): ?GuestCategory
    {
        $byCode = $this->findBySystemCode('default_adult');
        if ($byCode instanceof GuestCategory) {
            return $byCode;
        }

        return $this->createQueryBuilder('gc')
            ->where('gc.statisticalGroup = :g')
            ->andWhere('gc.active = :active')
            ->setParameter('g', GuestStatisticalGroup::ADULT)
            ->setParameter('active', true)
            ->orderBy('gc.sortOrder', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
