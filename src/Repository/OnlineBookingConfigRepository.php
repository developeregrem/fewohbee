<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OnlineBookingConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OnlineBookingConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OnlineBookingConfig::class);
    }

    /** Return the first config row to enforce singleton-style usage in services. */
    public function findSingleton(): ?OnlineBookingConfig
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
