<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown for expected public-booking validation and configuration errors.
 *
 * The message is always a translation key safe to display to the end user.
 */
class PublicBookingException extends \RuntimeException
{
}
