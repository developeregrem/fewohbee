<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

/** What the online check-in link of a reservation offers right now (GuestCheckInPolicy). */
enum GuestCheckInLinkState
{
    /** No link: feature off, stay over, cancelled, or nothing bookable to check in to. */
    case UNAVAILABLE;

    /** The guest can fill in or correct the form. */
    case EDITABLE;

    /** The guest can still open the page (arrival info), but no longer change anything. */
    case LOCKED;
}
