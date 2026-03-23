<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Amenity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Amenity|null find($id, $lockMode = null, $lockVersion = null)
 * @method Amenity|null findOneBy(array $criteria, array $orderBy = null)
 * @method Amenity[]    findAll()
 * @method Amenity[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AmenityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Amenity::class);
    }

    /**
     * @return array<string, Amenity[]> Amenities grouped by category
     */
    public function findAllGroupedByCategory(): array
    {
        $amenities = $this->createQueryBuilder('a')
            ->orderBy('a.category', 'ASC')
            ->addOrderBy('a.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($amenities as $amenity) {
            $grouped[$amenity->getCategory()][] = $amenity;
        }

        return $grouped;
    }
}
