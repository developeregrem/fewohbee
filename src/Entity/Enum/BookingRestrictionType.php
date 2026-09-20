<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * What a booking rule restricts. The case values are deliberately identical to the
 * restriction fields of the neutral channel contract, so a rule never has to be
 * renamed on its way to a portal.
 */
enum BookingRestrictionType: string
{
    /** The arrival day decides the minimum stay of the whole booking. */
    case MIN_STAY_ARRIVAL = 'min_stay_arrival';

    /** Booking any of the selected nights forces the whole booking to reach the minimum. */
    case MIN_STAY_THROUGH = 'min_stay_through';

    case CLOSED_TO_ARRIVAL = 'closed_to_arrival';

    case CLOSED_TO_DEPARTURE = 'closed_to_departure';

    /** Only the two minimum-stay types carry a night count; a closure has none. */
    public function needsMinNights(): bool
    {
        return self::MIN_STAY_ARRIVAL === $this || self::MIN_STAY_THROUGH === $this;
    }

    /**
     * Which day of a stay the selected weekdays refer to. Nights are labelled as
     * pairs ("Mon → Tue") in the UI, the other types as plain days.
     */
    public function selectsNights(): bool
    {
        return self::MIN_STAY_THROUGH === $this;
    }
}
