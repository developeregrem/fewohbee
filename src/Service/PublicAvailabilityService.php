<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\OnlineBookingConfig;
use App\Entity\RoomCategory;
use App\Repository\AppartmentRepository;
use App\Repository\ReservationRepository;

class PublicAvailabilityService
{
    public function __construct(
        private readonly AppartmentRepository $appartmentRepository,
        private readonly ReservationRepository $reservationRepository,
        private readonly OnlineBookingConfigService $configService,
        private readonly OnlineBookingRestrictionService $restrictionService,
        private readonly PublicPricingService $pricingService,
    ) {
    }

    /**
     * Return grouped availability by room type for the public booking form.
     *
     * Each row includes an `occupancyOptions` array with pre-calculated prices
     * for each valid number-of-persons. Only occupancy levels that have a matching
     * price category are included.
     *
     * @return array<int, array{
     *   typeKey: string,
     *   typeLabel: string,
     *   typeDescription: ?string,
     *   maxGuests: int,
     *   availableCount: int,
     *   roomIds: int[],
     *   subsidiaryIds: int[],
     *   occupancyOptions: array<int, array{persons: int, totalPrice: float, totalPriceFormatted: string}>
     * }>
     */
    public function getAvailability(
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        int $persons,
        int $roomsCount,
        ?OnlineBookingConfig $config = null
    ): array {
        if ($dateFrom > $dateTo || $persons < 1 || $roomsCount < 1 || $persons < $roomsCount) {
            return [];
        }

        $config ??= $this->configService->getConfig();
        $allowedSubsidiaryIds = $this->configService->getAllowedSubsidiaryIds($config);
        $allowedRoomIds = $this->configService->getAllowedRoomIds($config);

        if ([] === $allowedRoomIds || [] === $allowedSubsidiaryIds) {
            return [];
        }

        $rooms = $this->appartmentRepository->findForPublicBooking($allowedRoomIds, $allowedSubsidiaryIds);
        $occupancyByRoomId = $this->reservationRepository->loadOccupancyByApartmentIdsWithoutStartEnd(
            $dateFrom,
            $dateTo,
            array_map(static fn (Appartment $room): int => (int) $room->getId(), $rooms)
        );

        $grouped = [];
        foreach ($rooms as $room) {
            if (!$this->isRoomAvailableForPublicBooking($room, $occupancyByRoomId)) {
                continue;
            }

            $category = $room->getRoomCategory();
            $typeKey = $category ? 'category:'.$category->getId() : 'apartment:'.$room->getId();
            $typeLabel = $category
                ? (string) ($category->getName() ?? $category->getAcronym() ?? 'Room')
                : trim(sprintf('%s - %s', (string) $room->getNumber(), (string) $room->getDescription()));
            $typeDescription = $category ? $this->buildCategoryDescription($category) : null;
            $maxGuests = (int) $room->getBedsMax();

            if (!isset($grouped[$typeKey])) {
                $grouped[$typeKey] = [
                    'typeKey' => $typeKey,
                    'typeLabel' => $typeLabel,
                    'typeDescription' => $typeDescription,
                    'maxGuests' => (int) $maxGuests,
                    'availableCount' => 0,
                    'roomIds' => [],
                    'subsidiaryIds' => [],
                    '_category' => $category,
                    '_sampleRoom' => $room,
                ];
            }

            $grouped[$typeKey]['availableCount']++;
            $grouped[$typeKey]['roomIds'][] = (int) $room->getId();
            $grouped[$typeKey]['subsidiaryIds'][] = (int) $room->getObject()->getId();
            $grouped[$typeKey]['maxGuests'] = max($grouped[$typeKey]['maxGuests'], (int) $room->getBedsMax());
        }

        $stayNights = (int) $dateFrom->diff($dateTo)->days;

        foreach ($grouped as $key => &$row) {
            sort($row['roomIds']);
            $row['subsidiaryIds'] = array_values(array_unique($row['subsidiaryIds']));
            sort($row['subsidiaryIds']);

            $category = $row['_category'] ?? null;

            // Apply minimum stay restriction: hide category if stay is too short
            if ($category instanceof RoomCategory) {
                if (!$this->restrictionService->isStayLongEnough($category, $dateFrom, $stayNights)) {
                    unset($grouped[$key]);
                    continue;
                }

                // Apply max rooms limit per category
                $maxRooms = $this->restrictionService->getMaxRoomsForCategory($category);
                if (null !== $maxRooms && $row['availableCount'] > $maxRooms) {
                    $row['availableCount'] = $maxRooms;
                    $row['roomIds'] = array_slice($row['roomIds'], 0, $maxRooms);
                }
            }

            // Compute occupancy options with prices (cap at requested persons count)
            $sampleRoom = $row['_sampleRoom'];
            $row['occupancyOptions'] = $this->pricingService->getOccupancyPrices(
                $category ?? $sampleRoom->getRoomCategory(),
                $sampleRoom,
                $dateFrom,
                $dateTo,
                min((int) $row['maxGuests'], $persons),
            );

            // Apply minimum occupancy restriction: remove occupancy options below threshold
            if ($category instanceof RoomCategory) {
                $minOccupancy = $this->restrictionService->getMinOccupancyForCategory($category);
                if (null !== $minOccupancy) {
                    $row['occupancyOptions'] = array_values(array_filter(
                        $row['occupancyOptions'],
                        static fn (array $opt): bool => $opt['persons'] >= $minOccupancy,
                    ));
                }
            }

            unset($row['_category'], $row['_sampleRoom']);

            // If no occupancy option has a valid price, hide this category entirely
            if ([] === $row['occupancyOptions']) {
                unset($grouped[$key]);
                continue;
            }
        }
        unset($row);

        return $this->reduceAvailabilityForPublicOutput($grouped, $persons, $roomsCount);
    }

    /**
     * Reduce public output to only room types that are relevant for the current request.
     *
     * Caps the displayed availability per type to the highest count that can actually
     * participate in a valid selection for the requested guests/rooms (DP feasibility check).
     *
     * @param array<string, array{
     *   typeKey: string,
     *   typeLabel: string,
     *   typeDescription: ?string,
     *   maxGuests: int,
     *   availableCount: int,
     *   roomIds: int[],
     *   subsidiaryIds: int[]
     * }> $grouped
     * @return array<int, array{
     *   typeKey: string,
     *   typeLabel: string,
     *   typeDescription: ?string,
     *   maxGuests: int,
     *   availableCount: int,
     *   roomIds: int[],
     *   subsidiaryIds: int[]
     * }>
     */
    private function reduceAvailabilityForPublicOutput(array $grouped, int $persons, int $roomsCount): array
    {
        if ([] === $grouped) {
            return [];
        }

        $rows = array_values($grouped);

        if ([] === $rows) {
            return [];
        }

        $filtered = [];
        foreach ($rows as $index => $row) {
            $maxFeasibleCount = $this->findMaximumFeasibleCountForType($rows, $index, $persons, $roomsCount);
            if ($maxFeasibleCount < 1) {
                continue;
            }

            $row['availableCount'] = $maxFeasibleCount;
            $row['roomIds'] = array_slice($row['roomIds'], 0, $maxFeasibleCount);
            $filtered[] = $row;
        }

        usort($filtered, static function (array $left, array $right): int {
            if ((int) $left['maxGuests'] === (int) $right['maxGuests']) {
                return strcmp((string) $left['typeLabel'], (string) $right['typeLabel']);
            }

            return (int) $left['maxGuests'] <=> (int) $right['maxGuests'];
        });

        return $filtered;
    }

    /**
     * Apply the current public-booking availability rule using preloaded occupancy.
     *
     * @param array<int, array{reservationCount: int, persons: int}> $occupancyByRoomId
     */
    private function isRoomAvailableForPublicBooking(Appartment $room, array $occupancyByRoomId): bool
    {
        $occupancy = $occupancyByRoomId[(int) $room->getId()] ?? null;
        if (null === $occupancy || 0 === $occupancy['reservationCount']) {
            return true;
        }

        if (!$room->isMultipleOccupancy()) {
            return false;
        }

        return $occupancy['persons'] <= (int) $room->getBedsMax();
    }

    /** Return the optional public-facing room category description. */
    private function buildCategoryDescription(RoomCategory $category): ?string
    {
        $details = trim((string) $category->getDetails());

        if ('' === $details) {
            return null;
        }

        return $details;
    }

    /**
     * Compute the highest count for one room type that can still be part of a valid selection.
     *
     * @param array<int, array{maxGuests: int, availableCount: int}> $rows
     */
    private function findMaximumFeasibleCountForType(array $rows, int $targetIndex, int $persons, int $roomsCount): int
    {
        $targetCapacity = (int) $rows[$targetIndex]['maxGuests'];
        $targetMaxCount = min((int) $rows[$targetIndex]['availableCount'], $roomsCount);
        $otherCapacities = $this->buildMaxCapacityByRoomCount($rows, $targetIndex, $roomsCount);

        for ($targetCount = $targetMaxCount; $targetCount >= 1; --$targetCount) {
            $remainingRooms = $roomsCount - $targetCount;
            $requiredCapacity = $persons - ($targetCount * $targetCapacity);
            $otherCapacity = $otherCapacities[$remainingRooms] ?? PHP_INT_MIN;

            if ($otherCapacity >= $requiredCapacity) {
                return $targetCount;
            }
        }

        return 0;
    }

    /**
     * Build a DP table with the maximum reachable guest capacity for an exact number of rooms.
     *
     * @param array<int, array{maxGuests: int, availableCount: int}> $rows
     * @return array<int, int>
     */
    private function buildMaxCapacityByRoomCount(array $rows, int $excludedIndex, int $roomsCount): array
    {
        $maxCapacity = array_fill(0, $roomsCount + 1, PHP_INT_MIN);
        $maxCapacity[0] = 0;

        foreach ($rows as $index => $row) {
            if ($index === $excludedIndex) {
                continue;
            }

            $capacity = (int) $row['maxGuests'];
            $availableCount = min((int) $row['availableCount'], $roomsCount);
            for ($copy = 0; $copy < $availableCount; ++$copy) {
                for ($usedRooms = $roomsCount; $usedRooms >= 1; --$usedRooms) {
                    if (PHP_INT_MIN === $maxCapacity[$usedRooms - 1]) {
                        continue;
                    }

                    $maxCapacity[$usedRooms] = max(
                        $maxCapacity[$usedRooms],
                        $maxCapacity[$usedRooms - 1] + $capacity
                    );
                }
            }
        }

        return $maxCapacity;
    }
}
