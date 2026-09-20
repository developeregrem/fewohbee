<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CalendarSyncImport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Provides queries for room-specific remote calendar import configurations.
 *
 * @extends ServiceEntityRepository<CalendarSyncImport>
 */
class CalendarSyncImportRepository extends ServiceEntityRepository
{
    /** Initialize the repository for calendar import entities. */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarSyncImport::class);
    }

    /**
     * Return imports that permit sharing portal-label filters, optionally excluding the current one.
     *
     * @return list<CalendarSyncImport>
     */
    public function findSummaryFilterSharingImports(?CalendarSyncImport $currentImport = null): array
    {
        $queryBuilder = $this->createQueryBuilder('calendarImport')
            ->andWhere('calendarImport.shareSummaryFilters = true')
            ->orderBy('calendarImport.id', 'ASC');

        if (null !== $currentImport?->getId()) {
            $queryBuilder
                ->andWhere('calendarImport != :currentImport')
                ->setParameter('currentImport', $currentImport);
        }

        return $queryBuilder->getQuery()->getResult();
    }
}
