<?php

declare(strict_types=1);

namespace App\Workflow\Attachment;

use App\Entity\MailAttachment;
use App\Entity\Template;

/**
 * A single attachment that has been materialized into bytes and is ready to be
 * sent and (optionally) persisted as a FileCorrespondence.
 */
final readonly class ResolvedAttachment
{
    public function __construct(
        public MailAttachment $mailAttachment,
        /** Human-readable name used for Correspondence::name and FileCorrespondence::fileName. */
        public string $displayName,
        /** Null means the attachment cannot be persisted as a correspondence. */
        public ?Template $template,
        /** Rendered HTML the PDF was built from; '' when only binary data exists. */
        public string $renderedHtml,
        /** Set only when the exact bytes must be preserved (hybrid ZUGFeRD PDF). */
        public ?string $binaryPayload = null,
    ) {
    }

    public function withMailAttachment(MailAttachment $mailAttachment): self
    {
        return new self(
            $mailAttachment,
            $this->displayName,
            $this->template,
            $this->renderedHtml,
            $this->binaryPayload,
        );
    }
}
