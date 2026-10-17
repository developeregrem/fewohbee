<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

/** The arriving main guest cannot be added to a full reservation guest list. */
final class GuestCheckInNoGuestSlotException extends \InvalidArgumentException
{
}
