<?php

declare(strict_types=1);

namespace App\Dto\PublicBooking;

/**
 * One selectable room in the public availability calendar.
 *
 * Carries the public identifier and a guest-facing label only — never the internal
 * id, the subsidiary or the room description, which may hold internal notes.
 */
final readonly class CalendarRoom
{
    public function __construct(
        public string $uuid,
        public string $label,
        /** Bed capacity, so guests can judge the fit before picking dates. Already public in the search. */
        public int $maxGuests,
    ) {
    }
}
