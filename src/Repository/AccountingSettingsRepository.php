<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountingSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AccountingSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountingSettings::class);
    }

    public function findSingleton(): ?AccountingSettings
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
