<?php

declare(strict_types=1);

namespace App\Service\Api;

use App\Entity\GuestCategory;
use App\Entity\ReservationOrigin;
use App\Repository\GuestCategoryRepository;
use App\Service\OnlineBooking\OnlineBookingConfigService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Validates the stay parameters (origin, guest counts, persons) that price quotes and bookings
 * share, for the REST API and the MCP tools alike.
 *
 * Every method throws \InvalidArgumentException with a message that is safe to show to clients.
 */
class StayParameterResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GuestCategoryRepository $guestCategoryRepository,
        private readonly OnlineBookingConfigService $bookingConfigService,
    ) {
    }

    /**
     * Prices are joined to a reservation origin, so a quote without one cannot be
     * calculated at all — different origins legitimately carry different price rows.
     * Falls back to the origin configured for online booking.
     */
    public function resolveOrigin(?int $originId): ReservationOrigin
    {
        if (null !== $originId) {
            $origin = $this->em->getRepository(ReservationOrigin::class)->find($originId);
            if (!$origin instanceof ReservationOrigin) {
                throw new \InvalidArgumentException("Unknown 'originId'.");
            }

            return $origin;
        }

        $origin = $this->bookingConfigService->getReservationOrigin();
        if (!$origin instanceof ReservationOrigin) {
            throw new \InvalidArgumentException("Parameter 'originId' is required: no default booking origin is configured.");
        }

        return $origin;
    }

    /**
     * @param mixed $param guest category id => head count; null or empty for none
     *
     * @return array<int, int> guest category id => head count, zero counts dropped
     */
    public function resolveGuestCounts(mixed $param): array
    {
        if (null === $param || '' === $param || [] === $param) {
            return [];
        }
        if (!\is_array($param)) {
            throw new \InvalidArgumentException("Parameter 'guestCounts' must be given as guestCounts[categoryId]=count.");
        }

        $categories = $this->activeCategories();

        $result = [];
        foreach ($param as $categoryId => $count) {
            $categoryId = (int) $categoryId;
            if (!isset($categories[$categoryId])) {
                throw new \InvalidArgumentException(sprintf("Unknown guest category '%d' in 'guestCounts'.", $categoryId));
            }
            if (!is_numeric($count) || (int) $count < 0) {
                throw new \InvalidArgumentException("Values in 'guestCounts' must be non-negative integers.");
            }
            if ((int) $count > 0) {
                $result[$categoryId] = (int) $count;
            }
        }

        return $result;
    }

    /**
     * Uses the explicit persons count, or derives the occupancy from the guest counts.
     *
     * @param array<int, int> $guestCounts
     */
    public function resolvePersons(?int $persons, array $guestCounts): int
    {
        if (null !== $persons) {
            return $persons;
        }

        // Occupancy is what the apartment price is matched against, and only categories
        // flagged as counting toward occupancy belong in it (an infant in a cot does not).
        $categories = $this->activeCategories();
        $sum = 0;
        foreach ($guestCounts as $categoryId => $count) {
            $category = $categories[$categoryId] ?? null;
            if ($category instanceof GuestCategory && $category->isCountedInOccupancy()) {
                $sum += $count;
            }
        }

        if ($sum < 1) {
            throw new \InvalidArgumentException("Parameter 'persons' is required when 'guestCounts' carries no occupancy-counted guests.");
        }

        return $sum;
    }

    /**
     * @return array<int, GuestCategory>
     */
    private function activeCategories(): array
    {
        $categories = [];
        foreach ($this->guestCategoryRepository->findActiveOrdered() as $category) {
            $categories[(int) $category->getId()] = $category;
        }

        return $categories;
    }
}
