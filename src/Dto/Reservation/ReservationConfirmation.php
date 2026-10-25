<?php

declare(strict_types=1);

namespace App\Dto\Reservation;

use App\Entity\Reservation;
use App\Entity\Template;

/**
 * What the one-click booking confirmation of a reservation sends: to whom, with which templates
 * and for which rooms of the booking.
 */
final readonly class ReservationConfirmation
{
    /**
     * @param list<string>  $recipients   the booker's addresses; with several the user picks one
     * @param Reservation[] $reservations the whole booking, ordered by arrival
     */
    public function __construct(
        public array $recipients,
        public ?Template $emailTemplate,
        public ?Template $pdfTemplate,
        public array $reservations,
    ) {
    }

    /** A confirmation needs an address and a mail template; the PDF is optional. */
    public function canBeSent(): bool
    {
        return [] !== $this->recipients && null !== $this->emailTemplate;
    }
}
