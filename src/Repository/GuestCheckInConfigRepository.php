<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GuestCheckInConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuestCheckInConfig>
 */
class GuestCheckInConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuestCheckInConfig::class);
    }

    /** Return the first config row to enforce singleton-style usage in services. */
    public function findSingleton(): ?GuestCheckInConfig
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
