<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn\Section;

use App\Entity\GuestCheckIn;

/**
 * Contributes blocks to the public check-in page, shown after the booking-details check.
 *
 * Implementations are collected by tag (config/services.yaml). Intended for information a
 * guest needs for the stay — arrival instructions today, e.g. door codes of a lock integration
 * later; such content sets the visibility flags of GuestCheckInSection accordingly. Section
 * templates get their own vars plus `formShown` (whether the check-in form is on the page).
 */
interface GuestCheckInSectionProviderInterface
{
    /** @return iterable<GuestCheckInSection> */
    public function getSections(GuestCheckIn $checkIn): iterable;
}
