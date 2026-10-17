<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

/** The guest's saved service request no longer matches an available reservation price. */
final class GuestCheckInExtrasConflictException extends \InvalidArgumentException
{
}
