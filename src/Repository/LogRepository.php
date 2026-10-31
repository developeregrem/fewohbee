<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Log;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Log>
 */
class LogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Log::class);
    }

    public function purgeOlderThan(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('l')
            ->delete()
            ->where('l.date < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
