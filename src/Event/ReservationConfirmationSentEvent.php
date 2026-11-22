<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Reservation;

/**
 * The booking confirmation from the general settings went out to the booker, sent right away
 * from the button or from the mail editor. Carries every room of the booking, the one the
 * confirmation was written for first, so workflows run once per booking. Dispatched after the
 * mail was recorded in the conversations.
 */
final class ReservationConfirmationSentEvent
{
    /** @param non-empty-list<Reservation> $reservations */
    public function __construct(
        public readonly array $reservations,
    ) {
    }
}
