<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PostalCodeData;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method PostalCodeData|null find($id, $lockMode = null, $lockVersion = null)
 * @method PostalCodeData|null findOneBy(array $criteria, array $orderBy = null)
 * @method PostalCodeData[]    findAll()
 * @method PostalCodeData[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PostalCodeDataRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostalCodeData::class);
    }

    /**
     * @return PostalCodeData[] Returns an array of PostalCodeData objects
     */
    public function findPlacesByCode(string $country, string $zip)
    {
        return $this->createQueryBuilder('p')
            ->where('p.countryCode = :country')
            ->andWhere('p.postalCode LIKE :zip')
            ->setParameter('country', $country)
            ->setParameter('zip', $zip.'%')
            ->orderBy('p.placeName', 'ASC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult()
        ;
    }

    /*
    public function findOneBySomeField($value): ?PostalCodeData
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
