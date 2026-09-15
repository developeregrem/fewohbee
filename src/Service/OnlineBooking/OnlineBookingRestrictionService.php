<?php

declare(strict_types=1);

namespace App\Service\OnlineBooking;

use App\Entity\OnlineBookingRoomCategoryLimit;
use App\Entity\RoomCategory;
use App\Repository\OnlineBookingRoomCategoryLimitRepository;
use App\Service\ReservationPeriodService;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The online booking's view on restrictions: it delegates every day-dependent rule to the
 * shared BookingRestrictionService and owns only the limits that exist for this sales
 * channel alone — how many rooms of a category may be sold online, the minimum occupancy
 * and the booking horizon.
 */
class OnlineBookingRestrictionService implements ResetInterface
{
    /** @var array<int, OnlineBookingRoomCategoryLimit>|null */
    private ?array $limitsByCategory = null;

    public function __construct(
        private readonly BookingRestrictionService $bookingRestrictions,
        private readonly OnlineBookingRoomCategoryLimitRepository $limitRepository,
        private readonly OnlineBookingConfigService $configService,
    ) {
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
     * Whether one stay passes every booking rule of its category: both minimum stays and
     * the arrival and departure closures. The departure date is passed in full because a
     * departure closure acts on a day that is not an occupied night.
     */
    public function isStayAllowed(RoomCategory $category, \DateTimeImmutable $arrivalDate, \DateTimeImmutable $departureDate): bool
    {
        $nights = (int) $arrivalDate->diff($departureDate)->days;
        if ($nights < 1 || $nights > ReservationPeriodService::MAX_NIGHTS) {
            return false;
        }

        return $this->bookingRestrictions->checkStay($category, $arrivalDate, $departureDate)->isAllowed();
    }

    public function reset(): void
    {
        $this->limitsByCategory = null;
    }
}
