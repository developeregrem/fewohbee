<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RoomCategoryImage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoomCategoryImage>
 */
class RoomCategoryImageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoomCategoryImage::class);
    }
}
