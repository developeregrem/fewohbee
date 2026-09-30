<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BankImportDraft;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BankImportDraft>
 */
class BankImportDraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankImportDraft::class);
    }

    public function purgeNotUpdatedSince(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('d')
            ->delete()
            ->where('d.updatedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
