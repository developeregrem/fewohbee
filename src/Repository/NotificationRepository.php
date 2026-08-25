<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * Counts notifications the user may see and has not read yet.
     *
     * Runs on every page render for the bell badge, so it stays a COUNT with a
     * NOT EXISTS subquery rather than a join that could multiply rows.
     *
     * @param string[] $roles the user's effective roles, including inherited ones
     */
    public function countUnreadFor(User $user, array $roles): int
    {
        return (int) $this->unreadQueryBuilder($user, $roles)
            ->select('COUNT(n.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param string[] $roles
     *
     * @return Notification[]
     */
    public function findUnreadFor(User $user, array $roles, int $limit): array
    {
        return $this->unreadQueryBuilder($user, $roles)
            ->select('n')
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Deletes notifications older than the given date. Returns the number of rows removed. */
    public function purgeOlderThan(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('n')
            ->delete()
            ->where('n.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    /** @param string[] $roles */
    private function unreadQueryBuilder(User $user, array $roles): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('n')
            ->andWhere('NOT EXISTS (
                SELECT 1 FROM App\Entity\NotificationRead r
                WHERE r.notification = n AND r.user = :user
            )')
            ->setParameter('user', $user);

        // A NULL required_role means "everyone"; anything else must be covered by
        // the roles the security component resolved for this user.
        if ([] === $roles) {
            $qb->andWhere('n.requiredRole IS NULL');
        } else {
            $qb->andWhere('n.requiredRole IS NULL OR n.requiredRole IN (:roles)')
                ->setParameter('roles', $roles);
        }

        return $qb;
    }
}
