<?php

declare(strict_types=1);

namespace App\Workflow\Attachment;

use App\Entity\MailAttachment;

/**
 * Result of resolving a workflow's attachment configuration.
 *
 * Warnings carry the reason why a configured attachment could not be produced.
 * They are appended to the workflow log summary so a "send anyway" run still
 * tells the user what was missing.
 */
final readonly class ResolvedAttachmentSet
{
    /**
     * @param ResolvedAttachment[] $attachments
     * @param string[]             $warnings
     */
    public function __construct(
        public array $attachments = [],
        public array $warnings = [],
    ) {
    }

    /** @return MailAttachment[] */
    public function mailAttachments(): array
    {
        return array_map(static fn (ResolvedAttachment $a): MailAttachment => $a->mailAttachment, $this->attachments);
    }

    public function count(): int
    {
        return count($this->attachments);
    }

    public function hasWarnings(): bool
    {
        return [] !== $this->warnings;
    }

    public function warningSummary(): string
    {
        return implode('; ', $this->warnings);
    }
}
