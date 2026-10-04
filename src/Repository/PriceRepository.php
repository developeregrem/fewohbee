<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountingAccount;
use App\Entity\Price;
use App\Entity\PriceComponent;
use App\Entity\Reservation;
use App\Entity\RoomCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Query\Expr;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Price|null find($id, $lockMode = null, $lockVersion = null)
 * @method Price|null findOneBy(array $criteria, array $orderBy = null)
 * @method Price[]    findAll()
 * @method Price[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PriceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Price::class);
    }

    public function getActiveAppartmentPrices()
    {
        $q = $this
            ->createQueryBuilder('p')
            ->select('p')
            ->where('p.type = 2 AND p.active = true')
            ->getQuery();

        $prices = null;
        try {
            $prices = $q->getResult();
        } catch (NoResultException $e) {
        }

        return $prices;
    }

    /**
     * All prices with their components and room categories, active ones first. Grouping by room
     * category happens in PriceService::groupByRoomCategories(), because a price can belong to
     * several categories and SQL cannot order by a collection.
     *
     * @return Price[]
     */
    public function findAllOrdered()
    {
        $q = $this->createQueryBuilder('p')
                ->addSelect('components', 'rc')
                ->leftJoin('p.components', 'components')
                ->leftJoin('p.roomCategories', 'rc')
                ->addOrderBy('p.active', 'DESC')
                ->addOrderBy('p.id', 'ASC')
                ->getQuery();

        try {
            return $q->getResult();
        } catch (NoResultException $e) {
            return [];
        }
    }

    public function getActiveMiscellaneousPrices()
    {
        $q = $this
            ->createQueryBuilder('p')
            ->select('p')
            ->where('p.type = 1 AND p.active = true')
            ->getQuery();

        $prices = null;
        try {
            $prices = $q->getResult();
        } catch (NoResultException $e) {
        }

        return $prices;
    }

    /**
     * Find all prices that conflicts with the current one that are valid the whole year.
     *
     * @return Price[]|null
     */
    public function findConflictingPricesWithoutPeriod(Price $price)
    {
        $q = $this->conflictingBaseQuery($price)
            /* select only proces where start and and is not set (valid for the whole year) */
            ->andWhere('p.allPeriods = true')
            ->getQuery();

        $prices = null;
        try {
            $prices = $q->getResult();
        } catch (NoResultException $e) {
        }

        return $prices;
    }

    /**
     * Find all prices that conflicts with the current one based on a given season.
     *
     * @return Price[]|null
     */
    public function findConflictingPricesWithPeriod(Price $price)
    {
        $prices = [];
        $periods = $price->getPricePeriods();
        foreach ($periods as $pricePeriod) {
            $q = $this->conflictingBaseQuery($price)
                /* find overlapping periods */
            ->andWhere('((pp.start >= :start AND pp.end <= :end) OR'
                .'(pp.start < :start AND pp.end >= :start) OR'
                .'(pp.start <= :end AND pp.end > :end) OR'
                .'(pp.start < :start AND pp.end > :end))');
            $resQ = $q->setParameter(':start', $pricePeriod->getStart())
                    ->setParameter(':end', $pricePeriod->getEnd())
                    ->getQuery();
            try {
                // Collect the conflicts of every period; keyed by id so a price overlapping
                // several periods is listed once.
                foreach ($resQ->getResult() as $conflict) {
                    $prices[$conflict->getId()] = $conflict;
                }
            } catch (NoResultException $e) {
            }
        }

        return array_values($prices);
    }

    /**
     * @return \Doctrine\ORM\QueryBuilder
     */
    private function conflictingBaseQuery(Price $price)
    {
        $q = $this
            ->createQueryBuilder('p')
            ->select('p, ro, pp')
            ->leftJoin('p.pricePeriods', 'pp')
            ->leftJoin('p.reservationOrigins', 'ro')
                /* select only room type and active prices */
            ->where('p.type = 2 AND p.active = true')
                /* make sure that all room specific fields match */
            ->andWhere('p.minStay = :ms and p.numberOfPersons = :nop')
                /* the prices share at least one room category */
            ->andWhere(':rcs MEMBER OF p.roomCategories')
                /* select only prices for the given reservation origin */
            ->andWhere(':ros MEMBER OF p.reservationOrigins')
                /* compare the weekdays whether there are conflicts, ignore all weekdays set to false and check only weekdays set to true */
            ->andWhere('((:ad = true AND p.allDays = true) OR (:mo = true AND p.monday = true) OR (:tu = true AND p.tuesday = true)'
                    .' OR (:we = true AND p.wednesday = true) OR (:th = true AND p.thursday = true)  OR (:fr = true AND p.friday = true)'
                    .' OR (:sa = true AND p.saturday = true) OR (:su = true AND p.sunday = true))')
            ->setParameter('rcs', $price->getRoomCategories())
            ->setParameter('nop', $price->getNumberOfPersons())
            ->setParameter('ms', $price->getMinStay())
            ->setParameter('ros', $price->getReservationOrigins())
            ->setParameter('ad', $price->getAllDays())
            ->setParameter('mo', $price->getMonday())
            ->setParameter('tu', $price->getTuesday())
            ->setParameter('we', $price->getWednesday())
            ->setParameter('th', $price->getThursday())
            ->setParameter('fr', $price->getFriday())
            ->setParameter('sa', $price->getSaturday())
            ->setParameter('su', $price->getSunday());

        return $q;
    }

    /**
     * Find apartment prices for a reservation. The Array is already ordered by priority.
     *
     * @return Price[]
     */
    public function findApartmentPrices(Reservation $reservation, int $stays)
    {
        $q = $this->getFindBaseQuery($reservation)
                /* select only room type */
            ->andWhere('p.type = 2')
                /* make sure that all room specific fields match */
            ->andWhere(':rc MEMBER OF p.roomCategories AND p.numberOfPersons = :nop AND p.minStay <= :ms')
            ->addOrderBy('p.minStay', 'DESC')
            ->setParameter('rc', $reservation->getAppartment()->getRoomCategory())
            ->setParameter('nop', $reservation->getPersons())
            ->setParameter('ms', $stays);

        try {
            return $q->getQuery()->getResult();
        } catch (NoResultException $e) {
            return [];
        }
    }

    /**
     * Find misc prices for a reservation. The Array is already ordered by priority.
     *
     * @return Price[]
     */
    public function findMiscPrices(Reservation $reservation)
    {
        $q = $this->getFindBaseQuery($reservation)
                /* select only room type */
            ->andWhere('p.type = 1');
        $this->addMiscRoomCategoryFilter($q, $reservation);

        try {
            return $q->getQuery()->getResult();
        } catch (NoResultException $e) {
            return [];
        }
    }

    /**
     * Find misc prices that are enabled for online booking.
     *
     * @return Price[]
     */
    public function findBookableOnlineExtras(Reservation $reservation): array
    {
        $q = $this->getFindBaseQuery($reservation)
            ->andWhere('p.type = 1')
            ->andWhere('p.isBookableOnline = true');
        $this->addMiscRoomCategoryFilter($q, $reservation);

        try {
            return $q->getQuery()->getResult();
        } catch (NoResultException $e) {
            return [];
        }
    }

    /**
     * Find misc prices that are marked as mandatory for online booking.
     *
     * @return Price[]
     */
    public function findMandatoryOnlineExtras(Reservation $reservation): array
    {
        $q = $this->getFindBaseQuery($reservation)
            ->andWhere('p.type = 1')
            ->andWhere('p.isMandatoryOnline = true');
        $this->addMiscRoomCategoryFilter($q, $reservation);

        try {
            return $q->getQuery()->getResult();
        } catch (NoResultException $e) {
            return [];
        }
    }

    /**
     * Base Query to find prices for a reservation.
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    private function getFindBaseQuery(Reservation $reservation)
    {
        $q = $this
            ->createQueryBuilder('p');
        $q->select('p, ro, pp')
                /* workaround to have prices with no period at the end of the result list */
            ->addSelect('CASE WHEN p.allPeriods = true THEN 1 ELSE 0 END as HIDDEN start_is_null')
            ->leftJoin('p.pricePeriods', 'pp')
            ->join('p.reservationOrigins', 'ro', Expr\Join::WITH, $q->expr()->eq('ro.id', ':roid'))
                /* select only room type and active prices */
            ->where('p.active = true')
                /* make sure that all room specific fields match */
            ->andWhere('(p.allPeriods = true) or ((pp.start >= :start AND pp.end <= :end) OR'
                .'(pp.start < :start AND pp.end >= :start) OR'
                .'(pp.start <= :end AND pp.end > :end) OR'
                .'(pp.start < :start AND pp.end > :end))')
                /* select only prices for the given reservation origin */
            ->addOrderBy('start_is_null', 'ASC')
            ->setParameter('start', $reservation->getStartDate())
            ->setParameter('end', $reservation->getEndDate())
            ->setParameter('roid', $reservation->getReservationOrigin()->getId());

        return $q;
    }

    /**
     * Restrict misc prices (type=1) to those that either have no room category (apply to everyone,
     * backwards compatible) or include the room category of the reservation's apartment.
     */
    private function addMiscRoomCategoryFilter(\Doctrine\ORM\QueryBuilder $q, Reservation $reservation): void
    {
        $category = $reservation->getAppartment()?->getRoomCategory();
        if (null === $category) {
            // Apartment without a category can only match category-less misc prices.
            $q->andWhere('p.roomCategories IS EMPTY');

            return;
        }

        // Parentheses are required: andWhere() does not wrap a raw OR string, so without them
        // AND/OR precedence would match category-bound prices regardless of the other conditions.
        $q->andWhere('(p.roomCategories IS EMPTY OR :rc MEMBER OF p.roomCategories)')
            ->setParameter('rc', $category);
    }

    /**
     * Count Price + PriceComponent entries whose revenueAccount belongs to a different chartPreset.
     */
    public function countStaleRevenueAccountRefs(string $preset): int
    {
        $priceStale = (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->join('p.revenueAccount', 'a')
            ->where('a.chartPreset IS NOT NULL')
            ->andWhere('a.chartPreset != :preset')
            ->setParameter('preset', $preset)
            ->getQuery()
            ->getSingleScalarResult();

        $componentStale = (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(\App\Entity\PriceComponent::class, 'c')
            ->join('c.revenueAccount', 'a')
            ->where('a.chartPreset IS NOT NULL')
            ->andWhere('a.chartPreset != :preset')
            ->setParameter('preset', $preset)
            ->getQuery()
            ->getSingleScalarResult();

        return $priceStale + $componentStale;
    }

    public function countByRevenueAccount(AccountingAccount $account): int
    {
        $priceRefs = (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.revenueAccount = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();

        $componentRefs = (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(PriceComponent::class, 'c')
            ->where('c.revenueAccount = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();

        return $priceRefs + $componentRefs;
    }

    /**
     * Find active prices linked to a specific reservation origin, grouped by type.
     *
     * @return array{room: Price[], misc: Price[], extras: Price[]}
     */
    public function findActivePricesByOrigin(int $originId): array
    {
        $prices = $this->createQueryBuilder('p')
            ->join('p.reservationOrigins', 'ro', Expr\Join::WITH, 'ro.id = :oid')
            ->where('p.active = true')
            ->setParameter('oid', $originId)
            ->addOrderBy('p.type', 'DESC')
            ->addOrderBy('p.numberOfPersons', 'ASC')
            ->addOrderBy('p.description', 'ASC')
            ->getQuery()
            ->getResult();

        $room = [];
        $misc = [];
        $extras = [];
        foreach ($prices as $price) {
            if (2 === (int) $price->getType()) {
                $room[] = $price;
            } elseif ($price->getIsBookableOnline()) {
                $extras[] = $price;
            } else {
                $misc[] = $price;
            }
        }

        return ['room' => $room, 'misc' => $misc, 'extras' => $extras];
    }

    /**
     * Price catalogue for the REST API: the configured price rows themselves, with the
     * relations a consumer needs to render a price table (periods, origins, components).
     *
     * @param int[] $originIds
     *
     * @return Price[]
     */
    public function findForCatalogue(
        ?int $type = null,
        ?int $roomCategoryId = null,
        array $originIds = [],
        ?bool $active = true,
        ?bool $bookableOnline = null,
    ): array {
        $q = $this->createQueryBuilder('p')
            ->select('p, pp, ro, pc, rc')
            ->leftJoin('p.pricePeriods', 'pp')
            ->leftJoin('p.reservationOrigins', 'ro')
            ->leftJoin('p.components', 'pc')
            ->leftJoin('p.roomCategories', 'rc')
            ->addOrderBy('p.type', 'DESC')
            ->addOrderBy('rc.name', 'ASC')
            ->addOrderBy('p.numberOfPersons', 'ASC')
            ->addOrderBy('p.minStay', 'ASC')
            ->addOrderBy('p.description', 'ASC');

        if (null !== $type) {
            $q->andWhere('p.type = :type')->setParameter('type', $type);
        }
        if (null !== $roomCategoryId) {
            // MEMBER OF instead of filtering on the fetch-joined 'rc': that would truncate the
            // hydrated category collection to the requested category.
            $q->andWhere(':rcid MEMBER OF p.roomCategories')->setParameter('rcid', $roomCategoryId);
        }
        if (null !== $active) {
            $q->andWhere('p.active = :active')->setParameter('active', $active);
        }
        if (null !== $bookableOnline) {
            $q->andWhere('p.isBookableOnline = :bo')->setParameter('bo', $bookableOnline);
        }
        if ([] !== $originIds) {
            // Separate join alias: filtering on the fetch-joined 'ro' would truncate the
            // hydrated origin collection to the filtered subset.
            $q->andWhere($q->expr()->exists(
                'SELECT 1 FROM App\\Entity\\Price pf JOIN pf.reservationOrigins rof WHERE pf = p AND rof.id IN (:oids)'
            ))->setParameter('oids', $originIds);
        }

        return $q->getQuery()->getResult();
    }

    /**
     * The occupancies (numberOfPersons) a room category actually has active apartment
     * prices for. The rate calendar iterates these instead of guessing 1..bedsMax:
     * findApartmentPrices() matches numberOfPersons exactly, so any other value yields
     * no price at all.
     *
     * @return int[] ascending
     */
    public function findOccupanciesForRoomCategory(RoomCategory $roomCategory): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('DISTINCT p.numberOfPersons AS nop')
            ->where('p.active = true')
            ->andWhere('p.type = 2')
            ->andWhere(':rc MEMBER OF p.roomCategories')
            ->andWhere('p.numberOfPersons IS NOT NULL')
            ->orderBy('p.numberOfPersons', 'ASC')
            ->setParameter('rc', $roomCategory)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['nop'], $rows);
    }
}
