<?php

namespace App\Repository;

use App\Entity\RoomCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Persistence\ManagerRegistry;

/**
 * @method RoomCategory|null find($id, $lockMode = null, $lockVersion = null)
 * @method RoomCategory|null findOneBy(array $criteria, array $orderBy = null)
 * @method RoomCategory[]    findAll()
 * @method RoomCategory[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class RoomCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoomCategory::class);
    }

    // /**
    //  * @return RoomCategory[] Returns an array of RoomCategory objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('r.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?RoomCategory
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
