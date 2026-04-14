<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountingAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AccountingAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountingAccount::class);
    }

    /**
     * @return AccountingAccount[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.accountNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findCashAccount(): ?AccountingAccount
    {
        return $this->findOneBy(['isCashAccount' => true]);
    }

    public function findByNumber(string $accountNumber): ?AccountingAccount
    {
        return $this->findOneBy(['accountNumber' => $accountNumber]);
    }

    public function createNonCashQueryBuilder(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->where('a.isCashAccount = false')
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.accountNumber', 'ASC');
    }

    /**
     * @return AccountingAccount[]
     */
    public function findByType(string $type): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.type = :type')
            ->setParameter('type', $type)
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.accountNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
