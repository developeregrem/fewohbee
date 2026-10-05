<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A reservation request was refused (unavailable room, invalid guest data, ...). The message is
 * English and safe to show to API and MCP clients.
 */
class ReservationBookingException extends \RuntimeException
{
}
