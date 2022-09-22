<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PricePeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method PricePeriod|null find($id, $lockMode = null, $lockVersion = null)
 * @method PricePeriod|null findOneBy(array $criteria, array $orderBy = null)
 * @method PricePeriod[]    findAll()
 * @method PricePeriod[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PricePeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PricePeriod::class);
    }

    // /**
    //  * @return PricePeriod[] Returns an array of PricePeriod objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?PricePeriod
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
