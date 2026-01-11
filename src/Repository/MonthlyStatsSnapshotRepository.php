<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonthlyStatsSnapshot;
use App\Entity\Subsidiary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MonthlyStatsSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonthlyStatsSnapshot::class);
    }

    /**
     * Find a single snapshot for a month/year and optional subsidiary.
     */
    public function findOneByMonthYearSubsidiary(int $month, int $year, ?Subsidiary $subsidiary): ?MonthlyStatsSnapshot
    {
        return $this->findOneBy([
            'month' => $month,
            'year' => $year,
            'subsidiary' => $subsidiary,
        ]);
    }
}
