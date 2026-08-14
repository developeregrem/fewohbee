<?php

namespace App\Entity\Enum;

/**
 * How guests find their stay on the public booking page.
 *
 * The two modes are alternatives, never both at once: each one owns the first step
 * and produces the same room selection, after which the flow continues identically
 * with occupancy, extras, prices and guest details.
 */
enum PublicBookingMode: string
{
    /**
     * Guests enter a period, party size and room count and pick from the matching
     * room types. The right choice whenever more than one room is bookable.
     */
    case SEARCH = 'search';

    /**
     * Guests see the occupancy of one accommodation and drag out their stay.
     * Meant for single-property hosts: exactly one room is booked per request.
     */
    case CALENDAR = 'calendar';
}
