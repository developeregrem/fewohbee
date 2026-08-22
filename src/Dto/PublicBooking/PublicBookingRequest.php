<?php

declare(strict_types=1);

namespace App\Dto\PublicBooking;

/**
 * Everything one public booking POST carries, already parsed and validated.
 *
 * Built by {@see \App\Service\PublicBookingRequestMapper}. Its existence is the
 * signal that the search input was sound: dates, occupancy and room count are
 * guaranteed to be present, so consumers no longer re-check them.
 */
final readonly class PublicBookingRequest
{
    /**
     * @param array<int, int>                  $guestCounts        guest category id => count
     * @param array<string, array<int, int>>   $occupancySelection typeKey => [persons => quantity]
     * @param array<int, int>                  $extrasSelection    price id => quantity
     */
    public function __construct(
        public string $intent,
        public \DateTimeImmutable $dateFrom,
        public \DateTimeImmutable $dateTo,
        /** Effective occupancy — derived from the guest counts when the wizard supplied them. */
        public int $persons,
        public int $roomsCount,
        public array $guestCounts,
        public array $occupancySelection,
        public array $extrasSelection,
        public BookerInput $booker,
    ) {
    }
}
