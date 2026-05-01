<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountingAccount;
use App\Entity\BankImportFingerprint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BankImportFingerprintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankImportFingerprint::class);
    }

    /**
     * Returns the subset of the given hashes that already exist for this bank account.
     *
     * @param string[] $hashes
     *
     * @return string[]
     */
    public function findExistingHashes(AccountingAccount $bankAccount, array $hashes): array
    {
        if ([] === $hashes) {
            return [];
        }

        $rows = $this->createQueryBuilder('f')
            ->select('f.rawHash')
            ->andWhere('f.bankAccount = :account')
            ->andWhere('f.rawHash IN (:hashes)')
            ->setParameter('account', $bankAccount)
            ->setParameter('hashes', $hashes)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'rawHash');
    }

    public function findByHash(AccountingAccount $bankAccount, string $hash): ?BankImportFingerprint
    {
        return $this->findOneBy([
            'bankAccount' => $bankAccount,
            'rawHash' => $hash,
        ]);
    }
}
