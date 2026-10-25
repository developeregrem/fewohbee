<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Dto\Reservation\ReservationConfirmation;
use App\Entity\Reservation;
use App\Entity\Template;
use App\Repository\ReservationRepository;
use App\Repository\TemplateRepository;
use App\Workflow\Action\ComposedEmail;
use App\Workflow\Action\SendTemplateEmailAction;
use App\Workflow\Attachment\WorkflowAttachmentResolver;
use App\Workflow\WorkflowSkippedException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sends the booking confirmation of a reservation with one click: the default reservation mail
 * template to the booker, with the default reservation PDF attached when there is one. Only
 * templates explicitly marked as default count; without a default mail template nothing is sent,
 * since whichever template came first by name could be a text nobody meant for guests.
 * Which text fits (private or business, one room or several) is up to the templates.
 */
final class ReservationConfirmationService
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly EntityManagerInterface $em,
        private readonly SendTemplateEmailAction $sendTemplateEmail,
    ) {
    }

    public function prepare(Reservation $reservation): ReservationConfirmation
    {
        return new ReservationConfirmation(
            $reservation->getBooker()?->getEmails() ?? [],
            $this->defaultTemplate('TEMPLATE_RESERVATION_EMAIL'),
            $this->defaultTemplate('TEMPLATE_RESERVATION_PDF'),
            $this->bookingGroup($reservation),
        );
    }

    /**
     * Renders the confirmation exactly as send() would, in the booker's language, without
     * sending or recording anything. The PDF is rendered too, so a broken PDF shows up here.
     * $email as for send().
     *
     * @throws WorkflowSkippedException as for send()
     * @throws \RuntimeException        when a template does not render, with a message for the user
     */
    public function preview(Reservation $reservation, ?string $email = null): ComposedEmail
    {
        $confirmation = $this->prepare($reservation);

        return $this->sendTemplateEmail->compose($this->config($confirmation, $email), $reservation, ['allReservations' => $confirmation->reservations]);
    }

    /**
     * Sends the confirmation for the whole booking and records the mail in the conversations of
     * every room. $email picks one of the booker's addresses; null takes the first. Returns the
     * summary of what was sent.
     *
     * @throws WorkflowSkippedException when the booker has no address, no mail template is the default or the
     *                                  PDF cannot be rendered; the message is meant for the user
     * @throws \RuntimeException        when a template does not render, with a message for the user
     */
    public function send(Reservation $reservation, ?string $email = null): string
    {
        $confirmation = $this->prepare($reservation);

        return $this->sendTemplateEmail->execute($this->config($confirmation, $email), $reservation, ['allReservations' => $confirmation->reservations]);
    }

    /**
     * Action config shared by preview and send, so the preview shows what goes out.
     *
     * @return array<string, mixed>
     */
    private function config(ReservationConfirmation $confirmation, ?string $email): array
    {
        $attachments = [];
        if (null !== $confirmation->pdfTemplate) {
            $attachments[] = [
                'type' => WorkflowAttachmentResolver::TYPE_PDF_TEMPLATE,
                'templateId' => $confirmation->pdfTemplate->getId(),
            ];
        }

        return [
            'templateId' => $confirmation->emailTemplate?->getId() ?? 0,
            'recipientType' => 'booker_email',
            'bookerEmail' => $email,
            'attachments' => $attachments,
            // A confirmation without the promised PDF is worse than none.
            'attachmentPolicy' => WorkflowAttachmentResolver::POLICY_REQUIRE_ALL,
        ];
    }

    /**
     * All rooms booked together with $reservation, or just $reservation for a single booking.
     *
     * @return Reservation[]
     */
    private function bookingGroup(Reservation $reservation): array
    {
        $groupUuid = $reservation->getBookingGroupUuid();
        $group = null !== $groupUuid ? $this->reservations->findByBookingGroupUuid($groupUuid) : [];

        return [] !== $group ? $group : [$reservation];
    }

    /**
     * The template of a type marked as default, or null when none is. Hidden templates and
     * language variants never count: the base template is chosen here, and
     * SendTemplateEmailAction swaps in the booker's variant when sending.
     */
    private function defaultTemplate(string $typeName): ?Template
    {
        /** @var TemplateRepository $repository */
        $repository = $this->em->getRepository(Template::class);

        foreach ($repository->loadByTypeName([$typeName]) as $template) {
            if ($template->getIsDefault() && !$template->isHidden() && !$template->isVariant()) {
                return $template;
            }
        }

        return null;
    }
}
