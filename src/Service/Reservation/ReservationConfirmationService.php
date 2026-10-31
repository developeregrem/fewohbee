<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Dto\Reservation\ReservationConfirmation;
use App\Entity\Reservation;
use App\Entity\Template;
use App\Repository\ReservationRepository;
use App\Service\AppSettingsService;
use App\Workflow\Action\SendTemplateEmailAction;
use App\Workflow\Attachment\WorkflowAttachmentResolver;
use App\Workflow\WorkflowSkippedException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The booking confirmation of a reservation, with the mail template and attachments chosen in
 * the general settings: either prepared for the mail editor, where the user reviews and sends it
 * like any other conversation, or sent right away when the settings say so.
 */
final class ReservationConfirmationService
{
    public function __construct(
        private readonly AppSettingsService $appSettings,
        private readonly ReservationRepository $reservations,
        private readonly WorkflowAttachmentResolver $attachmentResolver,
        private readonly EntityManagerInterface $em,
        private readonly SendTemplateEmailAction $sendTemplateEmail,
    ) {
    }

    /** The mail template from the settings; null switches the confirmation button off. */
    public function emailTemplate(): ?Template
    {
        return $this->appSettings->getSettings()->getConfirmationTemplate();
    }

    /** Whether the button sends the confirmation right away instead of opening the editor. */
    public function sendsImmediately(): bool
    {
        return $this->appSettings->getSettings()->isConfirmationSendImmediately();
    }

    /**
     * Sends the confirmation for the whole booking without the editor and records the mail and
     * its attachments in the conversations of every room. $email picks one of the booker's
     * addresses; null takes the first. Unlike prepare(), a missing attachment stops the mail,
     * since nobody sees it before it goes out.
     *
     * @throws \LogicException          when no mail template is set
     * @throws WorkflowSkippedException when the booker has no address (or not $email) or an
     *                                  attachment cannot be rendered; the message is meant for the user
     * @throws \RuntimeException        when the mail template does not render, with a message for the user
     */
    public function send(Reservation $reservation, ?string $email = null): void
    {
        $settings = $this->appSettings->getSettings();
        $template = $settings->getConfirmationTemplate() ?? throw new \LogicException('No confirmation template is set.');

        $this->sendTemplateEmail->execute([
            'templateId' => $template->getId(),
            'recipientType' => 'booker_email',
            'bookerEmail' => $email,
            'attachments' => $this->attachmentItems($settings->getConfirmationAttachments()),
            'attachmentPolicy' => WorkflowAttachmentResolver::POLICY_REQUIRE_ALL,
        ], $reservation, ['allReservations' => $this->bookingGroup($reservation)]);
    }

    /**
     * Picks the templates in the booker's language and stores the configured attachments as
     * file correspondences of every room of the booking, as adding them by hand in the editor
     * does. They stay in the conversations even when the mail is not sent. An attachment that
     * does not render is left out and reported in the warnings.
     *
     * Returns null when no mail template is set.
     *
     * @throws WorkflowSkippedException when the attachments together are too large to send
     */
    public function prepare(Reservation $reservation): ?ReservationConfirmation
    {
        $settings = $this->appSettings->getSettings();
        $template = $settings->getConfirmationTemplate();
        if (null === $template) {
            return null;
        }

        $locale = $reservation->getBooker()?->getLocale();
        $reservations = $this->bookingGroup($reservation);
        $items = $this->attachmentItems($settings->getConfirmationAttachments());
        $resolved = $this->attachmentResolver->resolve($items, $reservation, $reservations, WorkflowAttachmentResolver::POLICY_SKIP_MISSING, 0, $locale);

        $attachmentIds = [];
        foreach ($resolved->attachments as $attachment) {
            $files = [];
            foreach ($reservations as $groupReservation) {
                $file = $this->attachmentResolver->createFileCorrespondence($attachment, $groupReservation);
                if (null !== $file) {
                    $this->em->persist($file);
                    $files[(int) $groupReservation->getId()] = $file;
                }
            }
            $this->em->flush();
            if ([] !== $files) {
                $attachmentIds[] = array_map(static fn ($file): int => (int) $file->getId(), $files);
            }
        }

        return new ReservationConfirmation($template->variantFor($locale), $reservations, $attachmentIds, $resolved->warnings);
    }

    /**
     * The attachment templates as items for WorkflowAttachmentResolver.
     *
     * @param iterable<Template> $templates
     *
     * @return list<array{type: string, templateId: ?int}>
     */
    private function attachmentItems(iterable $templates): array
    {
        $items = [];
        foreach ($templates as $template) {
            $items[] = ['type' => WorkflowAttachmentResolver::TYPE_PDF_TEMPLATE, 'templateId' => $template->getId()];
        }

        return $items;
    }

    /**
     * All rooms booked together with $reservation, $reservation first so the editor addresses
     * its booker and returns to its details; just $reservation for a single booking.
     *
     * @return Reservation[]
     */
    private function bookingGroup(Reservation $reservation): array
    {
        $groupUuid = $reservation->getBookingGroupUuid();
        $others = null !== $groupUuid
            ? array_filter($this->reservations->findByBookingGroupUuid($groupUuid), static fn (Reservation $other): bool => $other->getId() !== $reservation->getId())
            : [];

        return [$reservation, ...array_values($others)];
    }
}
