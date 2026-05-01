<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BankCsvProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class BankCsvProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankCsvProfile::class);
    }

    /**
     * @return BankCsvProfile[]
     */
    public function findAllOrdered(): array
    {
        return $this->createOrderedQueryBuilder()
            ->getQuery()
            ->getResult();
    }

    public function createOrderedQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.name', 'ASC');
    }
}
