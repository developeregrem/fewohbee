<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\GuestCheckIn;
use App\Entity\Reservation;

/** A guest sent (or corrected) the online check-in form; dispatched after the data is stored. */
class GuestCheckInSubmittedEvent
{
    public function __construct(
        public readonly Reservation $reservation,
        public readonly GuestCheckIn $checkIn,
        public readonly bool $firstSubmission,
    ) {
    }
}
