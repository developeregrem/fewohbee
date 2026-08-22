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
        private readonly AvailabilityService $availabilityService,
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
     *   occupancyAvailableCounts: array<int, int>,
     *   priceAdjustment: array{direction: string, labels: array<int, string>}|null
     * }>
     *
     * @param array<int, int> $guestCounts category-id => count from the wizard. Not part of
     *   the pricing — the rows carry list prices — but it decides whether a room type gets
     *   the "this party's price differs" hint.
     */
    public function getAvailability(
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        int $persons,
        int $roomsCount,
        ?OnlineBookingConfig $config = null,
        array $guestCounts = []
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
        $roomIdList = array_map(static fn (Appartment $room): int => (int) $room->getId(), $rooms);
        $occupancyByRoomId = $this->reservationRepository->loadOccupancyByApartmentIdsWithoutStartEnd(
            $dateFrom,
            $dateTo,
            $roomIdList
        );
        // room blocks make a room unbookable regardless of bed math
        $blockedRoomIds = $this->availabilityService->getBlockedRoomIds($roomIdList, $dateFrom, $dateTo);

        $grouped = [];
        foreach ($rooms as $room) {
            if (in_array((int) $room->getId(), $blockedRoomIds, true)) {
                continue;
            }
            if (!$this->availabilityService->isRoomAvailableFromPreloadedOccupancy($room, $occupancyByRoomId)) {
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

            // The prices above are plain list prices. If the party carries guests whose
            // rate differs, say so here — the amount only becomes known once the guest
            // has distributed them over the rooms in the next step.
            $row['priceAdjustment'] = $this->pricingService->describeGuestPriceAdjustment(
                $sampleRoom,
                $dateFrom,
                $dateTo,
                $guestCounts,
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

        return $this->reduceAvailabilityForPublicOutput($grouped, $roomsCount);
    }

    /**
     * Offer for the one room a guest picked in the availability calendar.
     *
     * None of the search machinery applies here: no grouping across categories, no
     * feasibility check, and above all no reduction of the published count — there is
     * nothing left to conceal once the guest selected the accommodation themselves.
     * Occupancy options run up to the room's capacity because the party size is chosen
     * from them in the next step rather than stated up front.
     *
     * @param array<int, int> $guestCounts guest category id => count; empty until the guest states them
     *
     * @return array<int, array<string, mixed>> zero or one row, shaped like getAvailability()
     */
    public function getAvailabilityForRoom(
        Appartment $room,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        array $guestCounts = [],
    ): array {
        if ($dateFrom >= $dateTo) {
            return [];
        }

        // The guest may submit a stale range, so re-check rather than trust the calendar.
        if (!$this->availabilityService->isRoomAvailable($room, $dateFrom, $dateTo)) {
            return [];
        }

        $category = $room->getRoomCategory();
        $nights = (int) $dateFrom->diff($dateTo)->days;
        if ($category instanceof RoomCategory && !$this->restrictionService->isStayLongEnough($category, $dateFrom, $nights)) {
            return [];
        }

        $capacity = (int) $room->getBedsMax();
        $occupancyOptions = $this->pricingService->getOccupancyPrices(
            $category,
            $room,
            $dateFrom,
            $dateTo,
            $capacity,
        );

        if ($category instanceof RoomCategory) {
            $minOccupancy = $this->restrictionService->getMinOccupancyForCategory($category);
            if (null !== $minOccupancy) {
                $occupancyOptions = array_filter(
                    $occupancyOptions,
                    static fn (array $option): bool => $option['persons'] >= $minOccupancy,
                );
            }
        }

        if ([] === $occupancyOptions) {
            return [];
        }

        $roomId = (int) $room->getId();

        return [[
            'typeKey' => $category instanceof RoomCategory ? 'category:'.$category->getId() : 'apartment:'.$roomId,
            'typeLabel' => $category instanceof RoomCategory
                ? (string) ($category->getName() ?? $category->getAcronym() ?? 'Room')
                : trim(sprintf('%s - %s', (string) $room->getNumber(), (string) $room->getDescription())),
            'typeDescription' => $category instanceof RoomCategory ? $this->buildCategoryDescription($category) : null,
            'maxGuests' => $capacity,
            'availableCount' => 1,
            'roomIds' => [$roomId],
            'roomCapacities' => [$roomId => $capacity],
            'subsidiaryIds' => [(int) $room->getObject()->getId()],
            'occupancyOptions' => $occupancyOptions,
            'priceAdjustment' => $this->pricingService->describeGuestPriceAdjustment($room, $dateFrom, $dateTo, $guestCounts),
            'occupancyAvailableCounts' => array_fill_keys(
                array_map(static fn (array $option): int => (int) $option['persons'], $occupancyOptions),
                1
            ),
            'amenities' => $category instanceof RoomCategory ? $this->buildAmenityData($category) : [],
            'primaryImage' => $category instanceof RoomCategory ? $this->buildPrimaryImageData($category) : null,
            'images' => $category instanceof RoomCategory ? $this->buildImageData($category) : [],
        ]];
    }

    /**
     * Trim the public output to what the current request can actually use.
     *
     * The displayed availability per type is capped at the number of rooms the guest
     * asked for — offering "8 available" when they want two rooms tells anonymous
     * visitors more about the house than they need to know. Whether a particular
     * combination adds up to the party size is not decided here: an impossible pick
     * is rejected by the selection validation, with a message the guest can act on.
     *
     * @param array<string, array{
     *   typeKey: string,
     *   typeLabel: string,
     *   typeDescription: ?string,
     *   maxGuests: int,
     *   availableCount: int,
     *   roomIds: int[],
     *   roomCapacities: array<int, int>,
     *   subsidiaryIds: int[],
     *   occupancyOptions: array<int, array{persons: int, totalPrice: float, totalPriceFormatted: string}>,
     *   priceAdjustment: array{direction: string, labels: array<int, string>}|null
     * }> $grouped
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
     *   priceAdjustment: array{direction: string, labels: array<int, string>}|null,
     *   occupancyAvailableCounts: array<int, int>
     * }>
     */
    private function reduceAvailabilityForPublicOutput(array $grouped, int $roomsCount): array
    {
        $filtered = [];
        foreach ($grouped as $row) {
            $cappedCount = min((int) $row['availableCount'], $roomsCount);
            if ($cappedCount < 1) {
                continue;
            }

            $row['availableCount'] = $cappedCount;
            $row['roomIds'] = array_slice($row['roomIds'], 0, $cappedCount);
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
