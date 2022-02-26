<?php

namespace App\Repository;

use App\Entity\CalendarSync;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method CalendarSync|null find($id, $lockMode = null, $lockVersion = null)
 * @method CalendarSync|null findOneBy(array $criteria, array $orderBy = null)
 * @method CalendarSync[]    findAll()
 * @method CalendarSync[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CalendarSyncRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarSync::class);
    }

    // /**
    //  * @return CalendarSync[] Returns an array of CalendarSync objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?CalendarSync
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
