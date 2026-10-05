<?php

declare(strict_types=1);

namespace App\Dto\Reservation;

use App\Entity\Appartment;
use App\Entity\Price;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;

/**
 * Outcome of validating a booking request without saving it.
 */
final readonly class ReservationBookingPreview
{
    /**
     * @param array<int, int> $guestCounts effective guest counts (defaults applied)
     * @param list<string>    $warnings    reasons a guest could not book this online; staff may still book
     * @param list<Price>     $extras      misc prices (breakfast, dog, ...) that will be attached
     */
    public function __construct(
        public Appartment $apartment,
        public ReservationStatus $status,
        public ReservationOrigin $origin,
        public int $persons,
        public array $guestCounts,
        public array $warnings,
        public array $extras = [],
    ) {
    }
}
