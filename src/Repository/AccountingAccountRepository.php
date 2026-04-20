<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountingAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class AccountingAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountingAccount::class);
    }

    /**
     * Visible scope: accounts of the given preset plus all user-created accounts (chartPreset IS NULL).
     * If $preset is null, only user-created accounts are returned.
     */
    private function applyPresetScope(QueryBuilder $qb, ?string $preset, string $alias = 'a'): QueryBuilder
    {
        if (null === $preset) {
            return $qb->andWhere($alias.'.chartPreset IS NULL');
        }

        return $qb
            ->andWhere($alias.'.chartPreset = :preset OR '.$alias.'.chartPreset IS NULL')
            ->setParameter('preset', $preset);
    }

    /**
     * @return AccountingAccount[]
     */
    public function findAllOrdered(?string $preset = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.accountNumber', 'ASC');

        if (null !== $preset) {
            $this->applyPresetScope($qb, $preset);
        }

        return $qb->getQuery()->getResult();
    }

    public function findCashAccount(?string $preset = null): ?AccountingAccount
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.isCashAccount = true')
            ->setMaxResults(1);

        if (null !== $preset) {
            $this->applyPresetScope($qb, $preset);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findBankAccount(?string $preset = null): ?AccountingAccount
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.isBankAccount = true')
            ->setMaxResults(1);

        if (null !== $preset) {
            $this->applyPresetScope($qb, $preset);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findOpeningBalanceAccount(?string $preset = null): ?AccountingAccount
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.isOpeningBalanceAccount = true')
            ->setMaxResults(1);

        if (null !== $preset) {
            $this->applyPresetScope($qb, $preset);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Look up an account by number. With $preset given, the lookup is scoped to that preset
     * (plus user-created accounts) so that e.g. SKR03 1200 won't be hit when SKR04 is active.
     */
    public function findByNumber(string $accountNumber, ?string $preset = null): ?AccountingAccount
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.accountNumber = :number')
            ->setParameter('number', $accountNumber)
            ->setMaxResults(1);

        if (null !== $preset) {
            $this->applyPresetScope($qb, $preset);
            // Preset-scoped match wins over user-created when both exist for the same number.
            $qb->orderBy('a.chartPreset', 'DESC');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Strict lookup used by the seeder dedup: matches the exact (accountNumber, preset) tuple.
     * NULL preset only matches NULL.
     */
    public function findByNumberAndPreset(string $accountNumber, ?string $preset): ?AccountingAccount
    {
        return $this->findOneBy([
            'accountNumber' => $accountNumber,
            'chartPreset' => $preset,
        ]);
    }

    public function createOrderedQueryBuilder(?string $preset = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.accountNumber', 'ASC');

        if (null !== $preset) {
            $this->applyPresetScope($qb, $preset);
        }

        return $qb;
    }

    public function createNonCashQueryBuilder(?string $preset = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.isCashAccount = false')
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.accountNumber', 'ASC');

        if (null !== $preset) {
            $this->applyPresetScope($qb, $preset);
        }

        return $qb;
    }

    /**
     * @return AccountingAccount[]
     */
    public function findByType(string $type, ?string $preset = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.type = :type')
            ->setParameter('type', $type)
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.accountNumber', 'ASC');

        if (null !== $preset) {
            $this->applyPresetScope($qb, $preset);
        }

        return $qb->getQuery()->getResult();
    }
}
