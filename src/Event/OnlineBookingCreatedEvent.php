<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Customer;
use App\Entity\Reservation;

class OnlineBookingCreatedEvent
{
    /** @param Reservation[] $reservations */
    public function __construct(
        public readonly array $reservations,
        public readonly ?Customer $booker = null,
    ) {
    }
}
