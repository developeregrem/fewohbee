<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn\Section;

/** From which step of the check-in on a page section may be shown to the guest. */
enum GuestCheckInSectionAvailability: string
{
    /** As soon as the visitor confirmed the booking details. */
    case ALWAYS = 'always';

    /** Once the guest sent the check-in form, reviewed or not. */
    case AFTER_SUBMISSION = 'after_submission';

    /** Once staff confirmed the check-in, e.g. for access codes the house hands out only then. */
    case AFTER_CONFIRMATION = 'after_confirmation';
}
