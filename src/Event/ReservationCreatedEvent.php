<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Reservation;

class ReservationCreatedEvent
{
    /** @param Reservation[] $reservations */
    public function __construct(
        public readonly array $reservations,
    ) {
    }
}
