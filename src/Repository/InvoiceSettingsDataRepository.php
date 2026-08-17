<?php

namespace App\Repository;

use App\Entity\InvoiceSettingsData;
use App\Entity\Subsidiary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvoiceSettingsData>
 */
class InvoiceSettingsDataRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvoiceSettingsData::class);
    }

    /**
     * @param int $id The ID of the setting which should not be updated
     */
    public function setAllInactive(int $id = 0): void
    {
        $this->createQueryBuilder('i')
            ->update()
            ->set('i.isActive', '0')
            ->where('i.id != :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->execute();
    }

    /**
     * Issuer configured specifically for this branch, or null when the branch has none
     * and the globally active row should be used instead.
     */
    public function findForSubsidiary(Subsidiary $subsidiary): ?InvoiceSettingsData
    {
        return $this->findOneBy(['subsidiary' => $subsidiary]);
    }

    /**
     * The globally active issuer — the fallback for invoices without a branch and for
     * branches that have no issuer of their own.
     */
    public function findActive(): ?InvoiceSettingsData
    {
        return $this->findOneBy(['isActive' => true]);
    }
}
