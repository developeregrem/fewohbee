<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Reservation;

class CalendarImportBookingCreatedEvent
{
    public function __construct(
        public readonly Reservation $reservation,
    ) {
    }
}
