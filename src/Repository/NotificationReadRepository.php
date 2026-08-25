<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NotificationRead;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationRead>
 */
class NotificationReadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationRead::class);
    }

    /** @return int[] ids of the notifications this user has already read */
    public function findReadNotificationIdsFor(User $user): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.notification) AS notificationId')
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_map('intval', array_column($rows, 'notificationId'));
    }
}
