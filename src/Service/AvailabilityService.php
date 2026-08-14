<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\Reservation;
use App\Entity\RoomBlock;
use App\Repository\AppartmentRepository;
use App\Repository\ReservationRepository;
use App\Repository\RoomBlockRepository;

/**
 * Central room availability: capacity minus blocking reservations minus room blocks.
 */
class AvailabilityService
{
    public function __construct(
        private readonly ReservationRepository $reservationRepository,
        private readonly RoomBlockRepository $roomBlockRepository,
        private readonly AppartmentRepository $appartmentRepository,
    ) {
    }

    /**
     * Full room-level check. A room block always makes the room unavailable,
     * even for multipleOccupancy rooms (blocks act on the physical room).
     */
    public function isRoomAvailable(
        Appartment $room,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        int $numberOfPersons = 0,
        ?Reservation $ignoreReservation = null,
        ?RoomBlock $ignoreBlock = null,
    ): bool {
        if (!$room->isActive() || !$this->hasCapacity($room, $numberOfPersons)) {
            return false;
        }

        if (count($this->getConflictingBlocks($room, $start, $end, $ignoreBlock)) > 0) {
            return false;
        }

        $reservations = $this->getConflictingReservations($room, $start, $end, $ignoreReservation);
        if (count($reservations) > 0) {
            if (!$room->isMultipleOccupancy()) {
                return false;
            }

            return $numberOfPersons + $this->getMaximumConcurrentOccupancy($reservations, $start, $end)
                <= $room->getBedsMax();
        }

        return true;
    }

    /**
     * Maximum number of beds occupied at the same time in the requested period.
     * Reservation periods are treated as half-open intervals: a departure and
     * another arrival at the same instant do not overlap.
     *
     * @param Reservation[] $reservations
     */
    private function getMaximumConcurrentOccupancy(array $reservations, \DateTimeInterface $start, \DateTimeInterface $end): int
    {
        $periodStart = $start->getTimestamp();
        $periodEnd = $end->getTimestamp();
        $occupancyChanges = [];

        foreach ($reservations as $reservation) {
            $overlapStart = max($periodStart, $reservation->getStartDate()->getTimestamp());
            $overlapEnd = min($periodEnd, $reservation->getEndDate()->getTimestamp());
            if ($overlapStart >= $overlapEnd) {
                continue;
            }

            $persons = max(0, $reservation->getPersons());
            $occupancyChanges[$overlapStart] = ($occupancyChanges[$overlapStart] ?? 0) + $persons;
            $occupancyChanges[$overlapEnd] = ($occupancyChanges[$overlapEnd] ?? 0) - $persons;
        }

        ksort($occupancyChanges, SORT_NUMERIC);
        $currentOccupancy = 0;
        $maximumOccupancy = 0;
        foreach ($occupancyChanges as $change) {
            $currentOccupancy += $change;
            $maximumOccupancy = max($maximumOccupancy, $currentOccupancy);
        }

        return $maximumOccupancy;
    }

    /**
     * Whether the room can accommodate the requested persons on its own.
     */
    public function hasCapacity(Appartment $room, int $numberOfPersons): bool
    {
        return $numberOfPersons <= $room->getBedsMax();
    }

    /**
     * Blocking reservations truly overlapping the period (turnovers excluded).
     *
     * @return Reservation[]
     */
    public function getConflictingReservations(Appartment $room, \DateTimeInterface $start, \DateTimeInterface $end, ?Reservation $ignore = null): array
    {
        $reservations = $this->reservationRepository->loadReservationsForApartmentWithoutStartEnd($start, $end, $room);

        if (null !== $ignore) {
            $reservations = array_values(array_filter(
                $reservations,
                static fn (Reservation $r): bool => $r->getId() !== $ignore->getId()
            ));
        }

        return $reservations;
    }

    /**
     * Room blocks truly overlapping the period (a block may start on a departure day).
     *
     * @return RoomBlock[]
     */
    public function getConflictingBlocks(Appartment $room, \DateTimeInterface $start, \DateTimeInterface $end, ?RoomBlock $ignore = null): array
    {
        return $this->roomBlockRepository->findOverlappingForApartment($room, $start, $end, $ignore);
    }

    /**
     * Room ids (of the given set) that have at least one overlapping block.
     *
     * @param int[] $roomIds
     *
     * @return int[]
     */
    public function getBlockedRoomIds(array $roomIds, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        return array_keys($this->roomBlockRepository->findOverlappingByApartmentIds($start, $end, $roomIds));
    }

    /**
     * Available physical-room count per night for a subsidiary/category scope:
     * active rooms minus rooms occupied by a blocking reservation minus blocked rooms (deduplicated).
     *
     * @return array<string, int> keyed by Y-m-d (nights from $from up to, excluding, $toExclusive)
     */
    public function countAvailablePerDay(string|int $objectId, ?int $roomCategoryId, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array
    {
        $rooms = $this->appartmentRepository->findAllByProperty($objectId);
        if (null !== $roomCategoryId) {
            $rooms = array_filter(
                $rooms,
                static fn (Appartment $room): bool => $room->getRoomCategory()?->getId() === $roomCategoryId
            );
        }
        $roomIds = array_map(static fn (Appartment $room) => $room->getId(), $rooms);
        $roomIdSet = array_flip($roomIds);
        $totalRooms = count($roomIds);

        $spans = $this->reservationRepository->loadBlockingSpansForPeriod($from, $toExclusive, $objectId, $roomCategoryId);
        foreach ($this->roomBlockRepository->findForPeriod($from, $toExclusive, $objectId) as $block) {
            $room = $block->getAppartment();
            if (null !== $roomCategoryId && $room->getRoomCategory()?->getId() !== $roomCategoryId) {
                continue;
            }
            $spans[] = [
                'appartmentId' => $room->getId(),
                'startDate' => $block->getStartDate()->format('Y-m-d'),
                'endDate' => $block->getEndDate()->format('Y-m-d'),
            ];
        }

        $unavailable = $this->expandSpansPerDay($spans, $from, $toExclusive, $roomIdSet);

        $result = [];
        for ($day = $from; $day < $toExclusive; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $result[$key] = $totalRooms - count($unavailable[$key] ?? []);
        }

        return $result;
    }

    /**
     * Occupied nights of a single room, for the public availability calendar.
     *
     * Half-open like every other availability query: a departure day is not an
     * occupied night, so it stays selectable as the next guest's arrival day.
     *
     * @return array<string, true> set of occupied nights (Y-m-d) within [$from, $toExclusive)
     */
    public function getOccupiedNightsForRoom(Appartment $room, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array
    {
        $roomId = (int) $room->getId();
        // Scoping to the room's own property keeps the query small; the spans are
        // filtered down to this room anyway, so the wider fallback stays correct.
        $objectId = $room->getObject()->getId() ?? 'all';

        $spans = array_values(array_filter(
            $this->reservationRepository->loadBlockingSpansForPeriod($from, $toExclusive, $objectId),
            static fn (array $span): bool => (int) $span['appartmentId'] === $roomId
        ));

        foreach ($this->roomBlockRepository->findForPeriod($from, $toExclusive, $objectId) as $block) {
            if ((int) $block->getAppartment()->getId() !== $roomId) {
                continue;
            }
            $spans[] = [
                'appartmentId' => $roomId,
                'startDate' => $block->getStartDate()->format('Y-m-d'),
                'endDate' => $block->getEndDate()->format('Y-m-d'),
            ];
        }

        $occupied = [];
        foreach (array_keys($this->expandSpansPerDay($spans, $from, $toExclusive, [$roomId => true])) as $night) {
            $occupied[$night] = true;
        }

        return $occupied;
    }

    /**
     * Blocked room and bed counts per night for the statistics module.
     *
     * @return array<string, array{rooms: int, beds: int}> keyed by Y-m-d
     */
    public function getBlockedPerDay(string|int $objectId, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array
    {
        $bedsByRoomId = [];
        $spans = [];
        foreach ($this->roomBlockRepository->findForPeriod($from, $toExclusive, $objectId) as $block) {
            $room = $block->getAppartment();
            $bedsByRoomId[$room->getId()] = (int) $room->getBedsMax();
            $spans[] = [
                'appartmentId' => $room->getId(),
                'startDate' => $block->getStartDate()->format('Y-m-d'),
                'endDate' => $block->getEndDate()->format('Y-m-d'),
            ];
        }

        $blockedByDay = $this->expandSpansPerDay($spans, $from, $toExclusive);

        $result = [];
        for ($day = $from; $day < $toExclusive; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $roomIds = array_keys($blockedByDay[$key] ?? []);
            $beds = 0;
            foreach ($roomIds as $roomId) {
                $beds += $bedsByRoomId[$roomId];
            }
            $result[$key] = ['rooms' => count($roomIds), 'beds' => $beds];
        }

        return $result;
    }

    /**
     * Expand (room, start, endExclusive) spans into a per-night set of room ids,
     * clipped to the requested window and deduplicated per room and night.
     *
     * @param array<array{appartmentId: int, startDate: string, endDate: string}> $spans
     * @param array<int, mixed>|null                                              $restrictToRoomIds room-id set (as keys) to include, null = all
     *
     * @return array<string, array<int, true>> night => set of room ids
     */
    private function expandSpansPerDay(array $spans, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive, ?array $restrictToRoomIds = null): array
    {
        $result = [];
        foreach ($spans as $span) {
            $roomId = $span['appartmentId'];
            if (null !== $restrictToRoomIds && !isset($restrictToRoomIds[$roomId])) {
                continue;
            }
            $start = max($from, new \DateTimeImmutable($span['startDate']));
            $end = min($toExclusive, new \DateTimeImmutable($span['endDate']));
            for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
                $result[$day->format('Y-m-d')][$roomId] = true;
            }
        }

        return $result;
    }
}
