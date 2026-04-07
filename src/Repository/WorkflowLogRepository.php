<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WorkflowLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WorkflowLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowLog::class);
    }

    /** @return WorkflowLog[] */
    public function findRecentByWorkflow(int $workflowId, int $page = 1, int $perPage = 25): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.workflow = :workflowId')
            ->setParameter('workflowId', $workflowId)
            ->orderBy('l.executedAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countByWorkflow(int $workflowId): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.workflow = :workflowId')
            ->setParameter('workflowId', $workflowId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function hasSuccessfulExecution(int $workflowId, string $entityClass, int $entityId): bool
    {
        $count = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.workflow = :workflowId')
            ->andWhere('l.entityClass = :entityClass')
            ->andWhere('l.entityId = :entityId')
            ->andWhere('l.status = :status')
            ->setParameter('workflowId', $workflowId)
            ->setParameter('entityClass', $entityClass)
            ->setParameter('entityId', $entityId)
            ->setParameter('status', 'success')
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Load all entity IDs that were successfully processed for a given workflow+entityClass combination.
     * Use this instead of N individual hasSuccessfulExecution() calls.
     *
     * @return array<int, true>  Keys are entity IDs for O(1) isset() lookup
     */
    public function findProcessedEntityIds(int $workflowId, string $entityClass): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('l.entityId')
            ->where('l.workflow = :workflowId')
            ->andWhere('l.entityClass = :entityClass')
            ->andWhere('l.status = :status')
            ->andWhere('l.entityId IS NOT NULL')
            ->setParameter('workflowId', $workflowId)
            ->setParameter('entityClass', $entityClass)
            ->setParameter('status', 'success')
            ->getQuery()
            ->getScalarResult();

        return array_fill_keys(array_column($rows, 'entityId'), true);
    }

    public function purgeOlderThan(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('l')
            ->delete()
            ->where('l.executedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
