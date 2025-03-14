<?php

namespace App\Repository;

use App\Entity\InvoiceSettingsData;
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
}
