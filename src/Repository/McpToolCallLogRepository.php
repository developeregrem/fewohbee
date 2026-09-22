<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\McpToolCallLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<McpToolCallLog>
 */
class McpToolCallLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, McpToolCallLog::class);
    }

    /**
     * @return list<McpToolCallLog>
     */
    public function findLatest(int $limit): array
    {
        /** @var list<McpToolCallLog> $result */
        $result = $this->createQueryBuilder('l')
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function purgeOlderThan(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('l')
            ->delete()
            ->where('l.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
