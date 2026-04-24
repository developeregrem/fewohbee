<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Reservation;
use App\Entity\ReservationStatus;

class ReservationStatusChangedEvent
{
    public function __construct(
        public readonly Reservation $reservation,
        public readonly ?ReservationStatus $previousStatus,
    ) {
    }
}
