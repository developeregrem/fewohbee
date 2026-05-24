<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountingAccount;
use App\Entity\BankStatementImport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BankStatementImportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankStatementImport::class);
    }

    /**
     * @return BankStatementImport[]
     */
    public function findForAccount(AccountingAccount $bankAccount, int $limit = 50): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.bankAccount = :account')
            ->setParameter('account', $bankAccount)
            ->orderBy('i.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Checks whether a committed import already covers a given period for this account.
     * Used to warn users about overlapping imports.
     *
     * @return BankStatementImport[]
     */
    public function findOverlapping(AccountingAccount $bankAccount, \DateTime $from, \DateTime $to): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.bankAccount = :account')
            ->andWhere('i.status = :committed')
            ->andWhere('i.periodFrom <= :to AND i.periodTo >= :from')
            ->setParameter('account', $bankAccount)
            ->setParameter('committed', BankStatementImport::STATUS_COMMITTED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('i.committedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
