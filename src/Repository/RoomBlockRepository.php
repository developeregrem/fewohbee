<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Appartment;
use App\Entity\RoomBlock;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for room blocks (out-of-order periods); endDate is exclusive.
 */
class RoomBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoomBlock::class);
    }

    /**
     * Blocks truly overlapping the given period (exclusive bounds, same-day turnover allowed).
     *
     * @return RoomBlock[]
     */
    public function findOverlappingForApartment(Appartment $apartment, \DateTimeInterface $start, \DateTimeInterface $end, ?RoomBlock $ignore = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->andWhere('b.appartment = :apartment')
            ->andWhere('b.startDate < :end')
            ->andWhere('b.endDate > :start')
            ->setParameter('apartment', $apartment)
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->addOrderBy('b.startDate', 'ASC');

        if (null !== $ignore && null !== $ignore->getId()) {
            $qb->andWhere('b.id != :ignore')
                ->setParameter('ignore', $ignore->getId());
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Bulk load for the reservation table view; inclusive bounds so start/end days render.
     *
     * @param Appartment[] $apartments
     *
     * @return RoomBlock[]
     */
    public function findForApartments(\DateTimeInterface $start, \DateTimeInterface $end, array $apartments): array
    {
        if (0 === count($apartments)) {
            return [];
        }

        return $this->createQueryBuilder('b')
            ->addSelect('a')
            ->join('b.appartment', 'a')
            ->andWhere('b.appartment IN (:apartments)')
            ->andWhere('b.startDate <= :end')
            ->andWhere('b.endDate >= :start')
            ->setParameter('apartments', $apartments)
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->addOrderBy('b.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Blocks truly overlapping the period (exclusive bounds), keyed by apartment id.
     *
     * @param int[] $apartmentIds
     *
     * @return array<int, RoomBlock[]>
     */
    public function findOverlappingByApartmentIds(\DateTimeInterface $start, \DateTimeInterface $end, array $apartmentIds): array
    {
        if (0 === count($apartmentIds)) {
            return [];
        }

        $blocks = $this->createQueryBuilder('b')
            ->andWhere('IDENTITY(b.appartment) IN (:ids)')
            ->andWhere('b.startDate < :end')
            ->andWhere('b.endDate > :start')
            ->setParameter('ids', $apartmentIds, ArrayParameterType::INTEGER)
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($blocks as $block) {
            $map[$block->getAppartment()->getId()][] = $block;
        }

        return $map;
    }

    /**
     * Blocks overlapping the given period for the management list, newest first
     * (by start then end date), optionally filtered by subsidiary.
     *
     * @return RoomBlock[]
     */
    public function findFiltered(\DateTimeInterface $periodStart, \DateTimeInterface $periodEnd, ?Subsidiary $subsidiary = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->addSelect('a')
            ->join('b.appartment', 'a')
            ->andWhere('b.startDate < :periodEnd')
            ->andWhere('b.endDate > :periodStart')
            ->setParameter('periodStart', $periodStart->format('Y-m-d'))
            ->setParameter('periodEnd', $periodEnd->format('Y-m-d'))
            ->addOrderBy('b.startDate', 'DESC')
            ->addOrderBy('b.endDate', 'DESC')
            ->addOrderBy('a.number', 'ASC');

        if (null !== $subsidiary) {
            $qb->andWhere('a.object = :subsidiary')
                ->setParameter('subsidiary', $subsidiary);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Earliest start date and latest end date across all blocks, for building the year filter.
     *
     * @return array{min: ?\DateTimeInterface, max: ?\DateTimeInterface}
     */
    public function findDateBounds(): array
    {
        $row = $this->createQueryBuilder('b')
            ->select('MIN(b.startDate) AS minDate', 'MAX(b.endDate) AS maxDate')
            ->getQuery()
            ->getSingleResult();

        return [
            'min' => $row['minDate'] ? new \DateTimeImmutable($row['minDate']) : null,
            'max' => $row['maxDate'] ? new \DateTimeImmutable($row['maxDate']) : null,
        ];
    }

    /**
     * Blocks intersecting a period, optionally scoped by subsidiary and category (stats, AP-2).
     *
     * @return RoomBlock[]
     */
    public function findForPeriod(\DateTimeInterface $start, \DateTimeInterface $end, string|int $objectId = 'all', ?RoomCategory $category = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->addSelect('a')
            ->join('b.appartment', 'a')
            ->andWhere('b.startDate < :end')
            ->andWhere('b.endDate > :start')
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'));

        if ('all' !== $objectId) {
            $qb->andWhere('a.object = :objectId')
                ->setParameter('objectId', $objectId);
        }
        if (null !== $category) {
            $qb->andWhere('a.roomCategory = :category')
                ->setParameter('category', $category);
        }

        return $qb->getQuery()->getResult();
    }
}
