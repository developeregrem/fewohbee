<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountingAccount;
use App\Entity\BankImportRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BankImportRule>
 */
class BankImportRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankImportRule::class);
    }

    /**
     * Returns active rules for the given bank account plus all global rules,
     * ordered by priority descending (highest priority first).
     *
     * @return BankImportRule[]
     */
    public function findActiveForAccount(AccountingAccount $bankAccount): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.isEnabled = true')
            ->andWhere('r.bankAccount = :account OR r.bankAccount IS NULL')
            ->setParameter('account', $bankAccount)
            ->orderBy('r.priority', \SortDirection::Descending)
            ->addOrderBy('r.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return BankImportRule[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.priority', \SortDirection::Descending)
            ->addOrderBy('r.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }
}
