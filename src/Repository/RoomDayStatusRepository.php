<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Appartment;
use App\Entity\RoomDayStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for querying housekeeping status entries.
 */
class RoomDayStatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoomDayStatus::class);
    }

    /**
     * Load room-day statuses for a list of apartments within the given date range.
     *
     * @param Appartment[] $apartments
     *
     * @return array<int, array<string, RoomDayStatus>>
     */
    public function findForApartmentsAndDates(array $apartments, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        if (0 === count($apartments)) {
            return [];
        }

        $entries = $this->createQueryBuilder('rds')
            ->addSelect('a')
            ->addSelect('assignee')
            ->addSelect('updatedBy')
            ->leftJoin('rds.appartment', 'a')
            ->leftJoin('rds.assignedTo', 'assignee')
            ->leftJoin('rds.updatedBy', 'updatedBy')
            ->andWhere('rds.appartment IN (:apartments)')
            ->andWhere('rds.date >= :start')
            ->andWhere('rds.date <= :end')
            ->setParameter('apartments', $apartments)
            ->setParameter('start', $start, Types::DATE_IMMUTABLE)
            ->setParameter('end', $end, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($entries as $entry) {
            $dateKey = $entry->getDate()->format('Y-m-d');
            $map[$entry->getAppartment()->getId()][$dateKey] = $entry;
        }

        return $map;
    }
}
