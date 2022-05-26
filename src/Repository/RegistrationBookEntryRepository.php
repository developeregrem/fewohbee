<?php
namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Description of RegistrationBookRepository
 *
 */
class RegistrationBookEntryRepository extends EntityRepository
{

    public function findAll(): array
    {
        return $this->findBy(array(), array('date' => 'ASC'));
    }

    public function getLastReservationEndDate()
    {
        $q = $this
            ->createQueryBuilder('c')
            ->select('MAX(r.endDate)')
            ->leftJoin('c.reservation', 'r')
            ->getQuery();
        return $q->getSingleScalarResult();
    }

    public function findByFilter($search, $page = 1, $limit = 20)
    {
        $q = $this
            ->createQueryBuilder('c')
            ->select('c, r')
            ->where('c.lastname LIKE :search or c.firstname LIKE :search or c.company LIKE :search or c.year LIKE :search')
            ->join('c.reservation', 'r')
            ->setParameter('search', '%' . $search . '%')
            ->addOrderBy('c.year', 'DESC')
            ->addOrderBy('c.date', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery();

        $paginator = new Paginator($q, $fetchJoinCollection = false);

        return $paginator;
    }
    
    /**
     * Load all Reservations that are not in the registration book within the given period
     * @param type $start
     * @param type $end
     * @return array
     */
    public function getReservationsNotInBook($start, $end)
    {
        $q = $this->_em->createQueryBuilder()
            ->select('r')
            ->from('App\Entity\Reservation', 'r')
            ->where("r.endDate >= :start AND r.endDate <= :end")
            ->andWhere("r.id NOT IN(SELECT r2.id FROM App\Entity\RegistrationBookEntry rb "
                    . "LEFT JOIN App\Entity\Reservation r2 WITH r2.id=rb.reservation "
                    . "WHERE r2.endDate >= :start AND r2.endDate <= :end)")
            ->setParameter('start', $start->format("Y-m-d"))
            ->setParameter('end', $end->format("Y-m-d"))
            ->addOrderBy('r.endDate', 'ASC')
            ->getQuery();
        
        return $q->getResult();
    }
}
