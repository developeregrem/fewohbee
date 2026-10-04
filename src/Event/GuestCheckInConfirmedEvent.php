<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\GuestCheckIn;
use App\Entity\Reservation;

/**
 * Staff confirmed a guest's online check-in: the data is in the guest records now. Dispatched
 * after the flush, e.g. for workflows that hand out access information once a person checked it.
 */
class GuestCheckInConfirmedEvent
{
    public function __construct(
        public readonly Reservation $reservation,
        public readonly GuestCheckIn $checkIn,
    ) {
    }
}
