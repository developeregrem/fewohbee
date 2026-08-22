<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Entity\RoomBlock;
use App\Entity\RoomDayStatus;
use App\Entity\Subsidiary;
use App\Repository\AppartmentRepository;
use App\Repository\ReservationRepository;
use App\Repository\RoomBlockRepository;
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
        private readonly TranslatorInterface $translator,
        private readonly RoomBlockRepository $roomBlockRepository,
        private readonly ReservationNameResolver $reservationNameResolver,
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
    public function buildDayView(\DateTimeImmutable $date, ?Subsidiary $subsidiary, string $statusMode = 'blocking', ?array $statusIds = null): array
    {
        $apartments = $this->loadApartments($subsidiary);
        $reservations = $this->reservationRepository->findForHousekeepingRange($date, $date->modify('+1 day'), $subsidiary, $statusMode, $statusIds);
        $reservationsByApartment = $this->groupReservationsByApartment($reservations);
        $blocksByApartment = $this->loadBlocksByApartment($apartments, $date, $date);
        $statusMap = $this->loadStatusMap($apartments, $date, $date);
        $dateKey = $date->format('Y-m-d');

        $rows = [];
        foreach ($apartments as $apartment) {
            $apartmentReservations = $reservationsByApartment[$apartment->getId()] ?? [];
            $occupancy = $this->resolveOccupancyForDay($date, $apartmentReservations, $blocksByApartment[$apartment->getId()] ?? []);
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
     * Build a housekeeping view model for a date range including reservations.
     *
     * @return array{
     *     start: \DateTimeImmutable,
     *     end: \DateTimeImmutable,
     *     days: \DateTimeImmutable[],
     *     apartments: Appartment[],
     *     reservations: Reservation[],
     *     dayViews: array<string, array{
     *         date: \DateTimeImmutable,
     *         apartments: Appartment[],
     *         rows: array<int, array{
     *             apartment: Appartment,
     *             occupancyType: string,
     *             guestCount: int|null,
     *             reservationSummary: string|null,
     *             status: RoomDayStatus|null,
     *             apartmentReservations: Reservation[]
     *         }>
     *     }>
     * }
     */
    public function buildRangeView(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?Subsidiary $subsidiary,
        array $occupancyTypes,
        string $statusMode = 'blocking',
        ?array $statusIds = null
    ): array {
        $apartments = $this->loadApartments($subsidiary);
        $reservations = $this->reservationRepository->findForHousekeepingRange($start, $end->modify('+1 day'), $subsidiary, $statusMode, $statusIds);
        $reservationsByApartment = $this->groupReservationsByApartment($reservations);
        $blocksByApartment = $this->loadBlocksByApartment($apartments, $start, $end);
        $statusMap = $this->loadStatusMap($apartments, $start, $end);
        $days = $this->buildDaysRange($start, $end);

        $dayViews = [];
        foreach ($days as $day) {
            $dateKey = $day->format('Y-m-d');
            $rows = [];
            foreach ($apartments as $apartment) {
                $apartmentReservations = $reservationsByApartment[$apartment->getId()] ?? [];
                $occupancy = $this->resolveOccupancyForDay($day, $apartmentReservations, $blocksByApartment[$apartment->getId()] ?? []);
                $rows[] = [
                    'apartment' => $apartment,
                    'occupancyType' => $occupancy['type'],
                    'guestCount' => $occupancy['guestCount'],
                    'reservationSummary' => $occupancy['summary'],
                    'status' => $statusMap[$apartment->getId()][$dateKey] ?? null,
                    'apartmentReservations' => $this->filterReservationsForDate($day, $apartmentReservations),
                ];
            }

            $dayView = [
                'date' => $day,
                'apartments' => $apartments,
                'rows' => $rows,
            ];
            $dayViews[$dateKey] = $this->filterDayViewByOccupancy($dayView, $occupancyTypes);
        }

        return [
            'start' => $start,
            'end' => $end,
            'days' => $days,
            'apartments' => $apartments,
            'reservations' => $reservations,
            'dayViews' => $dayViews,
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
    public function buildWeekView(\DateTimeImmutable $start, \DateTimeImmutable $end, ?Subsidiary $subsidiary, string $statusMode = 'blocking', ?array $statusIds = null): array
    {
        $apartments = $this->loadApartments($subsidiary);
        $reservations = $this->reservationRepository->findForHousekeepingRange($start, $end->modify('+1 day'), $subsidiary, $statusMode, $statusIds);
        $reservationsByApartment = $this->groupReservationsByApartment($reservations);
        $blocksByApartment = $this->loadBlocksByApartment($apartments, $start, $end);
        $statusMap = $this->loadStatusMap($apartments, $start, $end);
        $days = $this->buildDaysRange($start, $end);

        $rows = [];
        foreach ($apartments as $apartment) {
            $apartmentReservations = $reservationsByApartment[$apartment->getId()] ?? [];
            $apartmentBlocks = $blocksByApartment[$apartment->getId()] ?? [];
            $dayEntries = [];
            foreach ($days as $day) {
                $dateKey = $day->format('Y-m-d');
                $occupancy = $this->resolveOccupancyForDay($day, $apartmentReservations, $apartmentBlocks);
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
     * Reservation activity (arrival/departure/turnover/stayover) takes precedence over a
     * room block: housekeeping still needs to see e.g. the checkout on the day a block starts.
     *
     * @param Reservation[] $reservations
     * @param RoomBlock[]   $blocks
     *
     * @return array{type: string, guestCount: int|null, summary: string|null}
     */
    public function resolveOccupancyForDay(\DateTimeImmutable $date, array $reservations, array $blocks = []): array
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

        foreach ($blocks as $block) {
            $startKey = $block->getStartDate()->format('Y-m-d');
            $endKey = $block->getEndDate()->format('Y-m-d');
            // endDate is exclusive: the room is free again on that day
            if ($startKey <= $dateKey && $endKey > $dateKey) {
                return [
                    'type' => 'BLOCKED',
                    'guestCount' => null,
                    'summary' => $block->getReason(),
                ];
            }
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
            'BLOCKED' => 'housekeeping.occupancy.blocked',
        ];
    }

    /**
     * @return string[]
     */
    public function getAllowedOccupancyTypes(): array
    {
        return ['FREE', 'STAYOVER', 'ARRIVAL', 'DEPARTURE', 'TURNOVER', 'BLOCKED'];
    }

    /**
     * Normalize the occupancy filter selection.
     *
     * @return string[]
     */
    public function normalizeOccupancyTypes(mixed $param): array
    {
        if (is_string($param)) {
            $values = array_filter(array_map('trim', explode(',', $param)));
        } elseif (is_array($param)) {
            $values = array_filter(array_map('trim', $param));
        } else {
            $values = [];
        }

        $allowed = $this->getAllowedOccupancyTypes();
        $filtered = array_values(array_intersect($allowed, $values));

        return [] === $filtered ? $allowed : $filtered;
    }

    /**
     * Normalize selected reservation status IDs against the list of known statuses.
     *
     * Empty input intentionally returns []: the repository helper interprets [] as
     * "fall back to blocking statuses only", which is the default behaviour the UI
     * expects when the user has not picked any specific status.
     *
     * @param iterable<ReservationStatus> $allStatuses
     *
     * @return int[]
     */
    public function normalizeReservationStatusIds(mixed $param, iterable $allStatuses): array
    {
        if (is_string($param)) {
            $values = array_filter(array_map('trim', explode(',', $param)), static fn (string $v): bool => '' !== $v);
        } elseif (is_array($param)) {
            $values = $param;
        } else {
            return [];
        }

        $allowed = [];
        foreach ($allStatuses as $status) {
            $allowed[(int) $status->getId()] = true;
        }

        $result = [];
        foreach ($values as $value) {
            $id = (int) $value;
            if ($id > 0 && isset($allowed[$id]) && !in_array($id, $result, true)) {
                $result[] = $id;
            }
        }

        return $result;
    }

    /**
     * @param array{
     *     date: \DateTimeImmutable,
     *     apartments: Appartment[],
     *     rows: array<int, array{
     *         apartment: Appartment,
     *         occupancyType: string,
     *         guestCount: int|null,
     *         reservationSummary: string|null,
     *         status: RoomDayStatus|null
     *     }>
     * } $dayView
     */
    public function filterDayViewByOccupancy(array $dayView, array $allowedTypes): array
    {
        $dayView['rows'] = array_values(array_filter($dayView['rows'], static function (array $row) use ($allowedTypes): bool {
            return in_array($row['occupancyType'], $allowedTypes, true);
        }));

        return $dayView;
    }

    /**
     * @param array{
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
     * } $weekView
     */
    public function filterWeekViewByOccupancy(array $weekView, array $allowedTypes): array
    {
        $weekView['rows'] = array_values(array_filter($weekView['rows'], static function (array $row) use ($allowedTypes): bool {
            foreach ($row['days'] as $cell) {
                if (in_array($cell['occupancyType'], $allowedTypes, true)) {
                    return true;
                }
            }

            return false;
        }));

        return $weekView;
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
     * Filter reservations matching the given date.
     *
     * @param Reservation[] $reservations
     *
     * @return Reservation[]
     */
    private function filterReservationsForDate(\DateTimeImmutable $date, array $reservations): array
    {
        $dateKey = $date->format('Y-m-d');

        return array_values(array_filter($reservations, static function (Reservation $reservation) use ($dateKey): bool {
            $startKey = $reservation->getStartDate()->format('Y-m-d');
            $endKey = $reservation->getEndDate()->format('Y-m-d');

            return $startKey <= $dateKey && $endKey >= $dateKey;
        }));
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
     */
    private function resolveReservationName(Reservation $reservation): string
    {
        return $this->reservationNameResolver->resolve($reservation);
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

    /**
     * Load room blocks for the given apartments and (inclusive) date range, grouped by apartment id.
     *
     * @param Appartment[] $apartments
     *
     * @return array<int, RoomBlock[]>
     */
    private function loadBlocksByApartment(array $apartments, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $grouped = [];
        foreach ($this->roomBlockRepository->findForApartments($start, $end, $apartments) as $block) {
            $grouped[$block->getAppartment()->getId()][] = $block;
        }

        return $grouped;
    }
}
