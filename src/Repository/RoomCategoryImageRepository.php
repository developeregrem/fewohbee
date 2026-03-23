<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RoomCategoryImage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method RoomCategoryImage|null find($id, $lockMode = null, $lockVersion = null)
 * @method RoomCategoryImage|null findOneBy(array $criteria, array $orderBy = null)
 * @method RoomCategoryImage[]    findAll()
 * @method RoomCategoryImage[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class RoomCategoryImageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoomCategoryImage::class);
    }
}
