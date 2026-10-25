<?php

declare(strict_types=1);

namespace App\Workflow\Action;

use App\Entity\Reservation;
use App\Entity\Template;
use App\Workflow\Attachment\ResolvedAttachmentSet;

/**
 * A template mail rendered and ready to go, before anything is sent or recorded: what
 * SendTemplateEmailAction sends, and what a preview shows.
 */
final readonly class ComposedEmail
{
    /**
     * @param Template      $template     the template actually rendered, i.e. the guest's language variant
     * @param Reservation[] $reservations the reservations the mail is recorded for
     */
    public function __construct(
        public string $recipient,
        public string $subject,
        public string $body,
        public Template $template,
        public ResolvedAttachmentSet $attachments,
        public array $reservations,
    ) {
    }
}
