<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DayPrice;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DayPrice>
 */
class DayPriceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DayPrice::class);
    }

    /**
     * The day prices of the nights from $from up to, excluding, $toExclusive.
     *
     * @return array<string, DayPrice> keyed by night, Y-m-d
     */
    public function findForWindow(Subsidiary $subsidiary, RoomCategory $category, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array
    {
        $rows = $this->createQueryBuilder('d')
            ->andWhere('d.subsidiary = :subsidiary')
            ->andWhere('d.roomCategory = :category')
            ->andWhere('d.night >= :from AND d.night < :to')
            ->setParameter('subsidiary', $subsidiary)
            ->setParameter('category', $category)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $toExclusive->format('Y-m-d'))
            ->getQuery()
            ->getResult();

        $byNight = [];
        foreach ($rows as $row) {
            $byNight[$row->getNight()->format('Y-m-d')] = $row;
        }

        return $byNight;
    }

    /** Nights already past need no day price: bookings keep the price they were promised. */
    public function deleteBefore(\DateTimeImmutable $day): void
    {
        $this->createQueryBuilder('d')
            ->delete()
            ->andWhere('d.night < :day')
            ->setParameter('day', $day->format('Y-m-d'))
            ->getQuery()
            ->execute();
    }
}
