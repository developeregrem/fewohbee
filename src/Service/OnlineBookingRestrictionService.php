<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\OnlineBookingMinStay;
use App\Entity\OnlineBookingMinStayOverride;
use App\Entity\OnlineBookingRoomCategoryLimit;
use App\Entity\RoomCategory;
use App\Repository\OnlineBookingMinStayOverrideRepository;
use App\Repository\OnlineBookingMinStayRepository;
use App\Repository\OnlineBookingRoomCategoryLimitRepository;
use Symfony\Contracts\Service\ResetInterface;

class OnlineBookingRestrictionService implements ResetInterface
{
    /**
     * Availability builds a room list per category and asks for min stay, max rooms
     * and min occupancy on every single row — the underlying tables are tiny and
     * change only through the settings UI, so one read per request is enough.
     *
     * @var array<int, OnlineBookingMinStay>|null
     */
    private ?array $minStayByCategory = null;

    /** @var array<int, OnlineBookingRoomCategoryLimit>|null */
    private ?array $limitsByCategory = null;

    /** @var array<string, OnlineBookingMinStayOverride[]> keyed by arrival date (Y-m-d) */
    private array $overridesByArrival = [];

    public function __construct(
        private readonly OnlineBookingMinStayRepository $minStayRepository,
        private readonly OnlineBookingMinStayOverrideRepository $overrideRepository,
        private readonly OnlineBookingRoomCategoryLimitRepository $limitRepository,
        private readonly OnlineBookingConfigService $configService,
    ) {
    }

    /**
     * Get the effective minimum nights for a room category on a given arrival date.
     *
     * Priority:
     * 1. Overrides matching the arrival date (highest minNights wins)
     *    - roomCategory=null overrides apply to all categories
     *    - roomCategory-specific overrides apply only to that category
     * 2. Default min stay for the category (weekday vs weekend)
     * 3. Fallback: 1 (no restriction)
     */
    public function getEffectiveMinNights(RoomCategory $category, \DateTimeImmutable $arrivalDate): int
    {
        $overrides = $this->loadOverridesForArrival($arrivalDate);
        $maxOverride = $this->resolveHighestOverride($overrides, $category);

        if (null !== $maxOverride) {
            return $maxOverride;
        }

        return $this->getDefaultMinNights($category, $arrivalDate);
    }

    /**
     * Get the default minimum nights (without overrides) for weekday/weekend distinction.
     *
     * Weekday = arrival Mon–Thu (ISO day 1–4), Weekend = arrival Fri–Sun (ISO day 5–7).
     */
    public function getDefaultMinNights(RoomCategory $category, \DateTimeImmutable $arrivalDate): int
    {
        $indexed = $this->minStayByCategory ??= $this->minStayRepository->findAllIndexedByCategory();
        $minStay = $indexed[$category->getId()] ?? null;

        if (null === $minStay) {
            return 1;
        }

        $isWeekend = $this->isWeekendArrival($arrivalDate);
        $nights = $isWeekend ? $minStay->getMinNightsWeekend() : $minStay->getMinNightsWeekday();

        return $nights ?? 1;
    }

    /**
     * Get the maximum number of rooms available for online booking for a given category.
     *
     * @return int|null null means no limit
     */
    public function getMaxRoomsForCategory(RoomCategory $category): ?int
    {
        $indexed = $this->limitsByCategory ??= $this->limitRepository->findAllIndexedByCategory();
        $limit = $indexed[$category->getId()] ?? null;

        return $limit?->getMaxRooms();
    }

    /**
     * Get the minimum occupancy for online booking for a given category.
     *
     * @return int|null null means no restriction (any occupancy allowed)
     */
    public function getMinOccupancyForCategory(RoomCategory $category): ?int
    {
        $indexed = $this->limitsByCategory ??= $this->limitRepository->findAllIndexedByCategory();
        $limit = $indexed[$category->getId()] ?? null;

        return $limit?->getMinOccupancy();
    }

    /**
     * Get the maximum departure date based on the booking horizon.
     *
     * @return \DateTimeImmutable|null null means no limit
     */
    public function getMaxDepartureDate(): ?\DateTimeImmutable
    {
        $config = $this->configService->getConfig();
        $months = $config->getBookingHorizonMonths();

        if (null === $months || $months < 1) {
            return null;
        }

        return (new \DateTimeImmutable('today'))->modify(sprintf('+%d months', $months));
    }

    /**
     * Check whether a given stay duration meets the minimum nights requirement
     * for a specific room category on a given arrival date.
     */
    public function isStayLongEnough(RoomCategory $category, \DateTimeImmutable $arrivalDate, int $stayNights): bool
    {
        return $stayNights >= $this->getEffectiveMinNights($category, $arrivalDate);
    }

    /**
     * Weekend = arrival on Friday (5), Saturday (6) or Sunday (7).
     */
    public function isWeekendArrival(\DateTimeImmutable $date): bool
    {
        $isoDay = (int) $date->format('N');

        return $isoDay >= 5;
    }

    /**
     * Overrides are looked up per arrival date, so the memo is keyed by that date.
     *
     * @return OnlineBookingMinStayOverride[]
     */
    private function loadOverridesForArrival(\DateTimeImmutable $arrivalDate): array
    {
        $key = $arrivalDate->format('Y-m-d');

        return $this->overridesByArrival[$key] ??= $this->overrideRepository->findActiveForArrival($arrivalDate);
    }

    /**
     * Drop the per-request memo so a long-running worker never serves settings
     * that the hotelier has changed in the meantime.
     */
    public function reset(): void
    {
        $this->minStayByCategory = null;
        $this->limitsByCategory = null;
        $this->overridesByArrival = [];
    }

    /**
     * From a list of overrides, find the highest minNights that applies to the given category.
     *
     * @param OnlineBookingMinStayOverride[] $overrides
     */
    private function resolveHighestOverride(array $overrides, RoomCategory $category): ?int
    {
        $max = null;

        foreach ($overrides as $override) {
            $overrideCategory = $override->getRoomCategory();

            // Override applies if: no category set (= all) OR matches this category
            if (null !== $overrideCategory && $overrideCategory->getId() !== $category->getId()) {
                continue;
            }

            $nights = $override->getMinNights();
            if (null === $max || $nights > $max) {
                $max = $nights;
            }
        }

        return $max;
    }
}
