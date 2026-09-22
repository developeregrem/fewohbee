<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Customer;
use App\Entity\Reservation;

/**
 * An AI assistant created a reservation through the MCP server. Handled like a booking from a
 * portal: it has its own workflow trigger, so staff are notified.
 */
class AssistantReservationCreatedEvent
{
    public function __construct(
        public readonly Reservation $reservation,
        public readonly ?Customer $booker,
        public readonly ?string $tokenPrefix = null,
    ) {
    }
}
