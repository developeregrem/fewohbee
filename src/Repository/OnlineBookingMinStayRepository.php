<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OnlineBookingMinStay;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OnlineBookingMinStayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OnlineBookingMinStay::class);
    }

    /** @return array<int, OnlineBookingMinStay> keyed by room category ID */
    public function findAllIndexedByCategory(): array
    {
        $rows = $this->findAll();
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row->getRoomCategory()->getId()] = $row;
        }

        return $indexed;
    }
}
