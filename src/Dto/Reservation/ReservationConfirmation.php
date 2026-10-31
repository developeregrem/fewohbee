<?php

declare(strict_types=1);

namespace App\Dto\Reservation;

use App\Entity\Reservation;
use App\Entity\Template;

/**
 * A booking confirmation prepared for the mail editor: the template in the booker's language,
 * the rooms of the booking and the attachments already stored as file correspondences.
 */
final readonly class ReservationConfirmation
{
    /**
     * @param Reservation[]          $reservations  the whole booking, the clicked reservation first
     * @param list<array<int, int>>  $attachmentIds per attachment the file correspondence of each
     *                                              reservation, keyed by reservation ID, as the
     *                                              session key templateAttachmentIds holds them
     * @param list<string>           $warnings      attachments left out, with the reason
     */
    public function __construct(
        public Template $emailTemplate,
        public array $reservations,
        public array $attachmentIds,
        public array $warnings,
    ) {
    }
}
