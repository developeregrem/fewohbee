<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\OnlineBookingConfig;
use App\Entity\RoomCategory;
use App\Repository\AppartmentRepository;
use App\Repository\ReservationRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

class PublicAvailabilityService
{
    public function __construct(
        private readonly AppartmentRepository $appartmentRepository,
        private readonly ReservationRepository $reservationRepository,
        private readonly OnlineBookingConfigService $configService,
        private readonly OnlineBookingRestrictionService $restrictionService,
        private readonly PublicPricingService $pricingService,
        private readonly RoomCategoryImageService $imageService,
        private readonly TranslatorInterface $translator,
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
     *   roomCapacities: array<int, int>,
     *   subsidiaryIds: int[],
     *   occupancyOptions: array<int, array{persons: int, totalPrice: float, totalPriceFormatted: string}>,
     *   occupancyAvailableCounts: array<int, int>
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
            $roomId = (int) $room->getId();
            $bedsMax = (int) $room->getBedsMax();

            // For multipleOccupancy rooms, reduce capacity by already occupied persons
            $effectiveCapacity = $bedsMax;
            if ($room->isMultipleOccupancy()) {
                $occupiedPersons = $occupancyByRoomId[$roomId]['persons'] ?? 0;
                $effectiveCapacity = $bedsMax - $occupiedPersons;
            }

            if (!isset($grouped[$typeKey])) {
                $grouped[$typeKey] = [
                    'typeKey' => $typeKey,
                    'typeLabel' => $typeLabel,
                    'typeDescription' => $typeDescription,
                    'maxGuests' => $effectiveCapacity,
                    'availableCount' => 0,
                    'roomIds' => [],
                    'roomCapacities' => [],
                    'subsidiaryIds' => [],
                    '_category' => $category,
                    '_sampleRoom' => $room,
                ];
            }

            $grouped[$typeKey]['availableCount']++;
            $grouped[$typeKey]['roomIds'][] = $roomId;
            $grouped[$typeKey]['roomCapacities'][$roomId] = $effectiveCapacity;
            $grouped[$typeKey]['subsidiaryIds'][] = (int) $room->getObject()->getId();
            $grouped[$typeKey]['maxGuests'] = max($grouped[$typeKey]['maxGuests'], $effectiveCapacity);
        }

        $stayNights = (int) $dateFrom->diff($dateTo)->days;

        foreach ($grouped as $key => &$row) {
            sort($row['roomIds']);
            $row['roomCapacities'] = array_intersect_key($row['roomCapacities'], array_flip($row['roomIds']));
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
                    $row['roomCapacities'] = array_intersect_key($row['roomCapacities'], array_flip($row['roomIds']));
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

            $row['occupancyAvailableCounts'] = $this->buildOccupancyAvailableCounts(
                $row['roomCapacities'],
                $row['occupancyOptions'],
            );

            // Build amenity and image data for the public booking page
            if ($category instanceof RoomCategory) {
                $row['amenities'] = $this->buildAmenityData($category);
                $row['primaryImage'] = $this->buildPrimaryImageData($category);
                $row['images'] = $this->buildImageData($category);
            } else {
                $row['amenities'] = [];
                $row['primaryImage'] = null;
                $row['images'] = [];
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
     *   roomCapacities: array<int, int>,
     *   subsidiaryIds: int[]
     * }> $grouped
     * @return array<int, array{
     *   typeKey: string,
     *   typeLabel: string,
     *   typeDescription: ?string,
     *   maxGuests: int,
     *   availableCount: int,
     *   roomIds: int[],
     *   roomCapacities: array<int, int>,
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
            $row['roomCapacities'] = array_intersect_key($row['roomCapacities'], array_flip($row['roomIds']));
            $row['occupancyAvailableCounts'] = $this->buildOccupancyAvailableCounts(
                $row['roomCapacities'],
                $row['occupancyOptions']
            );
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
     * Count how many concrete rooms in a category can satisfy each visible occupancy option.
     *
     * @param array<int, int> $roomCapacities
     * @param array<int|string, array{persons?: int}> $occupancyOptions
     * @return array<int, int>
     */
    private function buildOccupancyAvailableCounts(array $roomCapacities, array $occupancyOptions): array
    {
        $counts = [];

        foreach ($occupancyOptions as $option) {
            $persons = (int) ($option['persons'] ?? 0);
            if ($persons < 1) {
                continue;
            }

            $count = 0;
            foreach ($roomCapacities as $capacity) {
                if ((int) $capacity >= $persons) {
                    ++$count;
                }
            }

            $counts[$persons] = $count;
        }

        return $counts;
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

        return $occupancy['persons'] < (int) $room->getBedsMax();
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

    /**
     * Returns amenity data for a category: the first 6 for inline display,
     * the remaining amenities for the expandable section, and the total count.
     * Uses iconFaClass (Font Awesome) as the single icon source for both admin and public pages.
     *
     * @return array{items: list<array{slug: string, label: string, iconClass: string}>, remaining: list<array{slug: string, label: string, iconClass: string}>, totalCount: int}
     */
    private function buildAmenityData(RoomCategory $category): array
    {
        $items = [];
        $remaining = [];
        $count = 0;
        foreach ($category->getAmenities() as $amenity) {
            $entry = [
                'slug' => $amenity->getSlug(),
                'label' => $this->translator->trans('amenity.' . $amenity->getSlug()),
                'iconClass' => $amenity->getIconFaClass(),
            ];
            if ($count < 6) {
                $items[] = $entry;
            } else {
                $remaining[] = $entry;
            }
            ++$count;
        }

        return [
            'items' => $items,
            'remaining' => $remaining,
            'totalCount' => $count,
        ];
    }

    /**
     * Returns the primary image URLs for a category, or null if no images exist.
     *
     * @return array{thumbnailUrl: string, mediumUrl: string}|null
     */
    private function buildPrimaryImageData(RoomCategory $category): ?array
    {
        $primaryImage = $category->getPrimaryImage();
        if (null === $primaryImage) {
            return null;
        }

        return [
            'thumbnailUrl' => $this->imageService->getPublicUrl($primaryImage, 'thumb'),
            'mediumUrl' => $this->imageService->getPublicUrl($primaryImage, 'medium'),
        ];
    }

    /**
     * Returns all image URLs for a category (used for gallery/lightbox).
     *
     * @return list<array{thumbnailUrl: string, mediumUrl: string, fullUrl: string}>
     */
    private function buildImageData(RoomCategory $category): array
    {
        $images = [];
        foreach ($category->getImages() as $image) {
            $images[] = [
                'thumbnailUrl' => $this->imageService->getPublicUrl($image, 'thumb'),
                'mediumUrl' => $this->imageService->getPublicUrl($image, 'medium'),
                'fullUrl' => $this->imageService->getPublicUrl($image, 'original'),
            ];
        }

        return $images;
    }
}
