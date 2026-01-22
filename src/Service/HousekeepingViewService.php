<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\Reservation;
use App\Entity\RoomDayStatus;
use App\Entity\Subsidiary;
use App\Repository\AppartmentRepository;
use App\Repository\ReservationRepository;
use App\Repository\RoomDayStatusRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds housekeeping day/week views by combining reservations with stored statuses.
 */
class HousekeepingViewService
{
    public function __construct(
        private readonly AppartmentRepository $appartmentRepository,
        private readonly ReservationRepository $reservationRepository,
        private readonly RoomDayStatusRepository $roomDayStatusRepository,
        private readonly TranslatorInterface $translator
    ) {
    }

    /**
     * Build the housekeeping view model for a single day.
     *
     * @return array{
     *     date: \DateTimeImmutable,
     *     apartments: Appartment[],
     *     rows: array<int, array{
     *         apartment: Appartment,
     *         occupancyType: string,
     *         guestCount: int|null,
     *         reservationSummary: string|null,
     *         status: RoomDayStatus|null
     *     }>
     * }
     */
    public function buildDayView(\DateTimeImmutable $date, ?Subsidiary $subsidiary): array
    {
        $apartments = $this->loadApartments($subsidiary);
        $reservations = $this->loadReservations($date, $date->modify('+1 day'), $subsidiary);
        $reservationsByApartment = $this->groupReservationsByApartment($reservations);
        $statusMap = $this->loadStatusMap($apartments, $date, $date);
        $dateKey = $date->format('Y-m-d');

        $rows = [];
        foreach ($apartments as $apartment) {
            $apartmentReservations = $reservationsByApartment[$apartment->getId()] ?? [];
            $occupancy = $this->resolveOccupancyForDay($date, $apartmentReservations);
            $rows[] = [
                'apartment' => $apartment,
                'occupancyType' => $occupancy['type'],
                'guestCount' => $occupancy['guestCount'],
                'reservationSummary' => $occupancy['summary'],
                'status' => $statusMap[$apartment->getId()][$dateKey] ?? null,
            ];
        }

        return [
            'date' => $date,
            'apartments' => $apartments,
            'rows' => $rows,
        ];
    }

    /**
     * Build the housekeeping view model for a Monday-Sunday week.
     *
     * @return array{
     *     start: \DateTimeImmutable,
     *     end: \DateTimeImmutable,
     *     days: \DateTimeImmutable[],
     *     apartments: Appartment[],
     *     rows: array<int, array{
     *         apartment: Appartment,
     *         days: array<string, array{
     *             occupancyType: string,
     *             guestCount: int|null,
     *             reservationSummary: string|null,
     *             status: RoomDayStatus|null
     *         }>
     *     }>
     * }
     */
    public function buildWeekView(\DateTimeImmutable $start, \DateTimeImmutable $end, ?Subsidiary $subsidiary): array
    {
        $apartments = $this->loadApartments($subsidiary);
        $reservations = $this->loadReservations($start, $end->modify('+1 day'), $subsidiary);
        $reservationsByApartment = $this->groupReservationsByApartment($reservations);
        $statusMap = $this->loadStatusMap($apartments, $start, $end);
        $days = $this->buildDaysRange($start, $end);

        $rows = [];
        foreach ($apartments as $apartment) {
            $apartmentReservations = $reservationsByApartment[$apartment->getId()] ?? [];
            $dayEntries = [];
            foreach ($days as $day) {
                $dateKey = $day->format('Y-m-d');
                $occupancy = $this->resolveOccupancyForDay($day, $apartmentReservations);
                $dayEntries[$dateKey] = [
                    'occupancyType' => $occupancy['type'],
                    'guestCount' => $occupancy['guestCount'],
                    'reservationSummary' => $occupancy['summary'],
                    'status' => $statusMap[$apartment->getId()][$dateKey] ?? null,
                ];
            }
            $rows[] = [
                'apartment' => $apartment,
                'days' => $dayEntries,
            ];
        }

        return [
            'start' => $start,
            'end' => $end,
            'days' => $days,
            'apartments' => $apartments,
            'rows' => $rows,
        ];
    }

    /**
     * Resolve the occupancy type and display details for a single day.
     *
     * @param Reservation[] $reservations
     *
     * @return array{type: string, guestCount: int|null, summary: string|null}
     */
    public function resolveOccupancyForDay(\DateTimeImmutable $date, array $reservations): array
    {
        $arrivals = [];
        $departures = [];
        $stayovers = [];

        $dateKey = $date->format('Y-m-d');
        foreach ($reservations as $reservation) {
            $startKey = $reservation->getStartDate()->format('Y-m-d');
            $endKey = $reservation->getEndDate()->format('Y-m-d');

            if ($startKey === $dateKey) {
                $arrivals[] = $reservation;
            }
            if ($endKey === $dateKey) {
                $departures[] = $reservation;
            }
            if ($startKey < $dateKey && $endKey > $dateKey) {
                $stayovers[] = $reservation;
            }
        }

        if (!empty($arrivals) && !empty($departures)) {
            $primary = $arrivals[0];

            return [
                'type' => 'TURNOVER',
                'guestCount' => $primary->getPersons(),
                'summary' => $this->buildReservationSummary($primary, $arrivals, $departures),
            ];
        }

        if (!empty($arrivals)) {
            $primary = $arrivals[0];

            return [
                'type' => 'ARRIVAL',
                'guestCount' => $primary->getPersons(),
                'summary' => $this->buildReservationSummary($primary, $arrivals, $departures),
            ];
        }

        if (!empty($departures)) {
            $primary = $departures[0];

            return [
                'type' => 'DEPARTURE',
                'guestCount' => $primary->getPersons(),
                'summary' => $this->buildReservationSummary($primary, $arrivals, $departures),
            ];
        }

        if (!empty($stayovers)) {
            $primary = $stayovers[0];

            return [
                'type' => 'STAYOVER',
                'guestCount' => $primary->getPersons(),
                'summary' => $this->buildReservationSummary($primary, $arrivals, $departures),
            ];
        }

        return [
            'type' => 'FREE',
            'guestCount' => null,
            'summary' => null,
        ];
    }

    /**
     * Define translation keys for housekeeping status values.
     */
    public function getStatusLabels(): array
    {
        return [
            'OPEN' => 'housekeeping.status.open',
            'IN_PROGRESS' => 'housekeeping.status.in_progress',
            'CLEANED' => 'housekeeping.status.cleaned',
            'INSPECTED' => 'housekeeping.status.inspected',
        ];
    }

    /**
     * Define translation keys for occupancy types.
     */
    public function getOccupancyLabels(): array
    {
        return [
            'FREE' => 'housekeeping.occupancy.free',
            'STAYOVER' => 'housekeeping.occupancy.stayover',
            'ARRIVAL' => 'housekeeping.occupancy.arrival',
            'DEPARTURE' => 'housekeeping.occupancy.departure',
            'TURNOVER' => 'housekeeping.occupancy.turnover',
        ];
    }

    /**
     * Load apartments filtered by subsidiary if provided.
     *
     * @return Appartment[]
     */
    private function loadApartments(?Subsidiary $subsidiary): array
    {
        if (!$subsidiary instanceof Subsidiary) {
            return $this->appartmentRepository->findAll();
        }

        return $this->appartmentRepository->findAllByProperty($subsidiary->getId());
    }

    /**
     * Load reservations covering the given date range and subsidiary selection.
     *
     * @return Reservation[]
     */
    private function loadReservations(\DateTimeImmutable $start, \DateTimeImmutable $end, ?Subsidiary $subsidiary): array
    {
        $qb = $this->reservationRepository->createQueryBuilder('r')
            ->addSelect('a')
            ->addSelect('booker')
            ->addSelect('customer')
            ->leftJoin('r.appartment', 'a')
            ->leftJoin('r.booker', 'booker')
            ->leftJoin('r.customers', 'customer')
            ->distinct()
            ->andWhere('r.startDate < :end')
            ->andWhere('r.endDate >= :start')
            ->andWhere('r.isConflict = 0')
            ->andWhere('r.isConflictIgnored = 0')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->addOrderBy('r.startDate', 'ASC');

        if ($subsidiary instanceof Subsidiary) {
            $qb->andWhere('a.object = :subsidiary')
                ->setParameter('subsidiary', $subsidiary->getId());
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Group reservations by apartment id for faster lookups.
     *
     * @param Reservation[] $reservations
     *
     * @return array<int, Reservation[]>
     */
    private function groupReservationsByApartment(array $reservations): array
    {
        $grouped = [];
        foreach ($reservations as $reservation) {
            $apartment = $reservation->getAppartment();
            if (!$apartment instanceof Appartment) {
                continue;
            }
            $grouped[$apartment->getId()][] = $reservation;
        }

        return $grouped;
    }

    /**
     * Build a readable reservation summary for quick scanning.
     *
     * @param Reservation[] $arrivals
     * @param Reservation[] $departures
     *
     * @return string|null
     */
    private function buildReservationSummary(Reservation $primary, array $arrivals, array $departures): ?string
    {
        $name = $this->resolveReservationName($primary);
        if ('' === $name) {
            return null;
        }

        if (!empty($arrivals) && !empty($departures)) {
            $departureName = $this->resolveReservationName($departures[0]);
            if ('' !== $departureName && $departureName !== $name) {
                $departLabel = $this->translator->trans('housekeeping.summary.depart', [], 'Housekeeping');
                $arriveLabel = $this->translator->trans('housekeeping.summary.arrive', [], 'Housekeeping');

                return sprintf('%s: %s / %s: %s', $departLabel, $departureName, $arriveLabel, $name);
            }
        }

        return $name;
    }

    /**
     * Resolve a display name for a reservation from booker/import data.
     *
     * @return string
     */
    private function resolveReservationName(Reservation $reservation): string
    {
        $booker = $reservation->getBooker();
        if ($booker instanceof \App\Entity\Customer) {
            $business = $this->resolveBusinessCompany($booker);
            if (null !== $business) {
                $lastname = trim((string) $booker->getLastname());
                if ('' !== $lastname) {
                    return sprintf('%s (%s)', $business, $lastname);
                }

                return $business;
            }

            return trim(sprintf('%s %s', (string) $booker->getLastname(), (string) $booker->getFirstname()));
        }

        $import = $reservation->getCalendarSyncImport();
        if ($import instanceof \App\Entity\CalendarSyncImport) {
            $name = trim($import->getName());
            if ('' !== $name) {
                return $name;
            }
        }

        return '';
    }

    /**
     * Resolve the business company name for a customer if available.
     */
    private function resolveBusinessCompany(\App\Entity\Customer $customer): ?string
    {
        foreach ($customer->getCustomerAddresses() as $address) {
            if ('CUSTOMER_ADDRESS_TYPE_BUSINESS' === $address->getType()) {
                $company = trim((string) $address->getCompany());
                if ('' !== $company) {
                    return $company;
                }
            }
        }

        return null;
    }


    /**
     * Build an inclusive list of days between start and end.
     *
     * @return \DateTimeImmutable[]
     */
    private function buildDaysRange(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $days = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $days[] = $cursor;
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    /**
     * Load existing housekeeping status entries for the given apartments and date range.
     *
     * @param Appartment[] $apartments
     *
     * @return array<int, array<string, RoomDayStatus>>
     */
    private function loadStatusMap(array $apartments, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->roomDayStatusRepository->findForApartmentsAndDates($apartments, $start, $end);
    }
}
