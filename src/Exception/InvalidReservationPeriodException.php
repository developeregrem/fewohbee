<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Signals a reservation period that is malformed, reversed or too large to process safely.
 *
 * The exception message is a translation key that is safe to display to the user.
 */
final class InvalidReservationPeriodException extends \DomainException
{
}
