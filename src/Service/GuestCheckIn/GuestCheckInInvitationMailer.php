<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use App\Entity\Reservation;
use App\Workflow\Action\SendTemplateEmailAction;
use App\Workflow\WorkflowSkippedException;

/**
 * Sends the online check-in invitation from the reservation dialog, with the template chosen in
 * the check-in settings, to the booker.
 */
final class GuestCheckInInvitationMailer
{
    public function __construct(
        private readonly GuestCheckInInvitationService $invitations,
        private readonly SendTemplateEmailAction $sendTemplateEmail,
    ) {
    }

    /**
     * Mails the invitation for this one reservation and records it in its conversations. Rooms
     * booked together each have their own check-in, so each gets its own mail. $email picks one
     * of the booker's addresses; null takes the first.
     *
     * @throws WorkflowSkippedException when no template is set or the booker has no address; the
     *                                  message is meant for the user
     * @throws \RuntimeException        when the template does not render, with a message for the user
     */
    public function send(Reservation $reservation, ?string $email = null): void
    {
        $this->sendTemplateEmail->execute([
            'templateId' => $this->invitations->invitationTemplate()?->getId() ?? 0,
            'recipientType' => 'booker_email',
            'bookerEmail' => $email,
            'attachments' => [],
        ], $reservation, ['allReservations' => [$reservation]]);
    }
}
