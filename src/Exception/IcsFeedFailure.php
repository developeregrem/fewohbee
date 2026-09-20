<?php

declare(strict_types=1);

namespace App\Exception;

/** Identifies the externally meaningful reason an ICS feed could not be loaded. */
enum IcsFeedFailure: string
{
    case HttpStatus = 'http_status';
    case Unreachable = 'unreachable';
    case TooLarge = 'too_large';
}
