<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AppSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppSettings::class);
    }

    public function findSingleton(): ?AppSettings
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
