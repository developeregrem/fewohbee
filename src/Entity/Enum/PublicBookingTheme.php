<?php

namespace App\Entity\Enum;

/**
 * Visual theme of the public booking page.
 *
 * The value doubles as the template sub-directory below `templates/PublicBooking/themes/`,
 * so the enum acts as the whitelist for template resolution — never build that path from
 * a raw request or database string.
 */
enum PublicBookingTheme: string
{
    /**
     * Frozen legacy design. Its markup is a compatibility contract for hoteliers who
     * styled the booking page with custom CSS, so those templates must not be changed.
     */
    case CLASSIC = 'classic';

    /** Current design, default for new installations. */
    case MODERN = 'modern';

    /** Twig template path of the booking page for this theme. */
    public function bookTemplate(): string
    {
        return sprintf('PublicBooking/themes/%s/book.html.twig', $this->value);
    }
}
