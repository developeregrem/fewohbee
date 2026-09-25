<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Progress of a guest's online check-in.
 *
 * Discarding a submission returns it to OPEN, so the guest can fill in the form again.
 */
enum GuestCheckInStatus: string
{
    /** Link exists, the guest has not sent anything yet. */
    case OPEN = 'open';

    /** The guest sent the form; the data waits for the hotelier's review. */
    case SUBMITTED = 'submitted';

    /** The hotelier took the data over into the guest records; the submission itself is gone. */
    case APPLIED = 'applied';

    public function labelKey(): string
    {
        return 'guest_checkin.status.'.$this->value;
    }
}
