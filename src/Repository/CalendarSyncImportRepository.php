<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CalendarSyncImport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method CalendarSyncImport|null find($id, $lockMode = null, $lockVersion = null)
 * @method CalendarSyncImport|null findOneBy(array $criteria, array $orderBy = null)
 * @method CalendarSyncImport[]    findAll()
 * @method CalendarSyncImport[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CalendarSyncImportRepository extends ServiceEntityRepository
{
    /** Initialize the repository for calendar import entities. */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarSyncImport::class);
    }
}
