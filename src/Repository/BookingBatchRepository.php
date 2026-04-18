<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BookingBatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class BookingBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookingBatch::class);
    }

    /** @return array<int, array{year: int}> */
    public function getAvailableYears(): array
    {
        return $this->createQueryBuilder('b')
            ->select('b.year')
            ->groupBy('b.year')
            ->orderBy('b.year', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByFilter(int $year, int $page = 1, int $limit = 20): Paginator
    {
        $q = $this->createQueryBuilder('b')
            ->where('b.year = :year')
            ->setParameter('year', $year)
            ->orderBy('b.month', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery();

        return new Paginator($q, false);
    }

    public function findByYearAndMonth(int $year, int $month): ?BookingBatch
    {
        return $this->findOneBy(['year' => $year, 'month' => $month]);
    }

    public function getYoungestBatch(): ?BookingBatch
    {
        return $this->createQueryBuilder('b')
            ->orderBy('b.year', 'DESC')
            ->addOrderBy('b.month', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
