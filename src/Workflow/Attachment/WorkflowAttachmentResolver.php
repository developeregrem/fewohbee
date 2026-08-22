<?php

declare(strict_types=1);

namespace App\Workflow\Attachment;

use App\Entity\Enum\InvoiceStatus;
use App\Entity\FileCorrespondence;
use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use App\Entity\MailAttachment;
use App\Entity\Reservation;
use App\Entity\Template;
use App\Service\EInvoice\EInvoiceExportService;
use App\Service\EInvoice\EInvoiceReadinessService;
use App\Service\InvoiceService;
use App\Service\TemplatesService;
use App\Workflow\WorkflowSkippedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns a workflow's attachment configuration into ready-to-send MailAttachments.
 *
 * Shared by all email actions so the "render a document, attach it, remember it
 * in the correspondence history" logic exists exactly once.
 *
 * Config item shapes (see WorkflowController::loadAttachmentGroups for the UI side):
 *   ['type' => 'pdf_template', 'templateId' => 12]  – renders a PDF template
 *   ['type' => 'invoice_pdf']                       – all non-cancelled invoices
 *   ['type' => 'invoice_pdf_open']                  – only invoices with status OPEN
 */
class WorkflowAttachmentResolver
{
    public const POLICY_SKIP_MISSING = 'skip_missing';
    public const POLICY_REQUIRE_ALL = 'require_all';

    /**
     * Raw byte budget for all attachments of one mail. Base64 inflates this by ~1.37x,
     * which keeps us below the 20-25 MB message limit of common SMTP providers.
     */
    public const MAX_TOTAL_BYTES = 10 * 1024 * 1024;

    /** Template types a user may pick as an attachment. */
    private const ATTACHABLE_TEMPLATE_TYPES = ['TEMPLATE_FILE_PDF', 'TEMPLATE_RESERVATION_PDF'];

    private const TYPE_PDF_TEMPLATE = 'pdf_template';
    private const TYPE_INVOICE_PDF = 'invoice_pdf';
    private const TYPE_INVOICE_PDF_OPEN = 'invoice_pdf_open';

    public function __construct(
        private readonly TemplatesService $templatesService,
        private readonly InvoiceService $invoiceService,
        private readonly EInvoiceReadinessService $readinessService,
        private readonly EInvoiceExportService $einvoiceExportService,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<int, mixed> $items        raw actionConfig['attachments']
     * @param Reservation[]     $reservations render and persistence context
     * @param string            $policy       self::POLICY_*
     *
     * @throws WorkflowSkippedException when POLICY_REQUIRE_ALL cannot be satisfied,
     *                                  or when the attachments exceed MAX_TOTAL_BYTES
     */
    public function resolve(
        array $items,
        mixed $entity,
        array $reservations,
        string $policy = self::POLICY_SKIP_MISSING,
    ): ResolvedAttachmentSet {
        $normalized = $this->normalizeItems($items);
        if ([] === $normalized) {
            return new ResolvedAttachmentSet();
        }

        $attachments = [];
        $warnings = [];

        foreach ($normalized as $item) {
            switch ($item['type']) {
                case self::TYPE_PDF_TEMPLATE:
                    $resolved = $this->resolvePdfTemplate((int) $item['templateId'], $reservations, $warnings);
                    if (null !== $resolved) {
                        $attachments[] = $resolved;
                    }
                    break;

                case self::TYPE_INVOICE_PDF:
                case self::TYPE_INVOICE_PDF_OPEN:
                    $onlyOpen = self::TYPE_INVOICE_PDF_OPEN === $item['type'];
                    array_push($attachments, ...$this->resolveInvoices($entity, $reservations, $onlyOpen, $warnings));
                    break;
            }
        }

        $attachments = $this->dedupeFilenames($attachments);
        $this->assertTotalSize($attachments);

        if (self::POLICY_REQUIRE_ALL === $policy && [] !== $warnings) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_attachment_missing', [
                '%reason%' => implode('; ', $warnings),
            ]));
        }

        return new ResolvedAttachmentSet($attachments, $warnings);
    }

    /**
     * Builds the correspondence entry for a sent attachment so it shows up in the
     * reservation's history and stays downloadable.
     */
    public function createFileCorrespondence(ResolvedAttachment $attachment, Reservation $reservation): ?FileCorrespondence
    {
        if (!$attachment->template instanceof Template) {
            return null;
        }

        // Correspondence::name and FileCorrespondence::fileName are both string(100),
        // while invoice filenames follow a free-form user pattern.
        $name = mb_substr($attachment->displayName, 0, 100);

        $file = new FileCorrespondence();
        $file->setFileName($name)
             ->setName($name)
             ->setText($attachment->renderedHtml)
             ->setTemplate($attachment->template)
             ->setReservation($reservation);

        if (null !== $attachment->binaryPayload) {
            $file->setBinaryPayload($attachment->binaryPayload);
        }

        return $file;
    }

    /**
     * Drops malformed and duplicate items while preserving the configured order.
     *
     * @param array<int, mixed> $items
     *
     * @return array<int, array{type: string, templateId?: int}>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];
        $seen = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = $item['type'] ?? null;

            if (self::TYPE_PDF_TEMPLATE === $type) {
                $templateId = (int) ($item['templateId'] ?? 0);
                if ($templateId <= 0) {
                    continue;
                }
                $key = self::TYPE_PDF_TEMPLATE.':'.$templateId;
                $entry = ['type' => self::TYPE_PDF_TEMPLATE, 'templateId' => $templateId];
            } elseif (self::TYPE_INVOICE_PDF === $type || self::TYPE_INVOICE_PDF_OPEN === $type) {
                $key = $type;
                $entry = ['type' => $type];
            } else {
                continue;
            }

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * @param Reservation[] $reservations
     * @param string[]      $warnings
     */
    private function resolvePdfTemplate(int $templateId, array $reservations, array &$warnings): ?ResolvedAttachment
    {
        $template = $this->em->getRepository(Template::class)->find($templateId);
        if (!$template instanceof Template) {
            $warnings[] = $this->translator->trans('workflow.log.attachment_template_not_found', ['%id%' => $templateId]);

            return null;
        }

        $typeName = $template->getTemplateType()?->getName();
        if (!in_array($typeName, self::ATTACHABLE_TEMPLATE_TYPES, true)) {
            // Happens when the workflow's trigger entity changed after the attachment was picked.
            $warnings[] = $this->translator->trans('workflow.log.attachment_template_incompatible', ['%name%' => (string) $template->getName()]);

            return null;
        }

        $needsReservation = 'TEMPLATE_RESERVATION_PDF' === $typeName;
        if ($needsReservation && [] === $reservations) {
            $warnings[] = $this->translator->trans('workflow.log.attachment_no_reservation', ['%name%' => (string) $template->getName()]);

            return null;
        }

        // TEMPLATE_FILE_PDF is a static document; its preview provider ignores the input.
        $renderInput = $needsReservation ? $reservations : null;
        $baseName = $this->invoiceService->sanitizeFilename((string) $template->getName());

        try {
            $html = $this->templatesService->renderTemplate($templateId, $renderInput);
            $pdf = $this->templatesService->getPDFOutput($html, $baseName, $template);
        } catch (\Throwable $e) {
            $warnings[] = $this->translator->trans('workflow.log.attachment_render_failed', [
                '%name%' => (string) $template->getName(),
                '%error%' => $e->getMessage(),
            ]);

            return null;
        }

        return new ResolvedAttachment(
            new MailAttachment($pdf, $baseName.'.pdf', 'application/pdf'),
            (string) $template->getName(),
            $template,
            $html,
        );
    }

    /**
     * @param Reservation[] $reservations
     * @param string[]      $warnings
     *
     * @return ResolvedAttachment[]
     */
    private function resolveInvoices(mixed $entity, array $reservations, bool $onlyOpen, array &$warnings): array
    {
        $invoices = $this->collectInvoices($entity, $reservations, $onlyOpen);
        if ([] === $invoices) {
            $warnings[] = $this->translator->trans($onlyOpen
                ? 'workflow.log.attachment_no_open_invoice'
                : 'workflow.log.attachment_no_invoice');

            return [];
        }

        $pdfTemplate = $this->templatesService->getDefaultTemplate(
            $this->em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_INVOICE_PDF'])
        );
        if (!$pdfTemplate instanceof Template) {
            $warnings[] = $this->translator->trans('workflow.log.skipped_no_pdf_template');

            return [];
        }

        $resolved = [];

        foreach ($invoices as $invoice) {
            // Resolved per invoice: with per-branch issuers the company can differ between
            // the invoices of a single workflow run.
            $settings = $this->readinessService->resolveSettingsFor($invoice);
            $attachment = $this->resolveInvoice($invoice, $pdfTemplate, $settings, $warnings);
            if (null !== $attachment) {
                $resolved[] = $attachment;
            }
        }

        return $resolved;
    }

    /** @param string[] $warnings */
    private function resolveInvoice(
        Invoice $invoice,
        Template $pdfTemplate,
        ?InvoiceSettingsData $settings,
        array &$warnings,
    ): ?ResolvedAttachment {
        $hybridPdf = null;

        if ($settings instanceof InvoiceSettingsData && $this->readinessService->check($invoice, $settings)->ready) {
            try {
                $hybridPdf = $this->invoiceService->generateInvoicePdfXml(
                    $this->templatesService,
                    $this->einvoiceExportService,
                    $invoice,
                    $pdfTemplate,
                    $settings
                );
            } catch (\Throwable) {
                // Same behaviour as the "e-invoice preferred" mode: fall back to a plain PDF.
                $hybridPdf = null;
            }
        }

        $asEInvoice = null !== $hybridPdf;
        $baseName = $this->invoiceService->buildInvoiceExportFilename($invoice, $asEInvoice);

        try {
            $html = $this->templatesService->renderTemplate($pdfTemplate->getId(), $invoice->getId());
            $pdf = $hybridPdf ?? $this->templatesService->getPDFOutput($html, $baseName, $pdfTemplate);
        } catch (\Throwable $e) {
            $warnings[] = $this->translator->trans('workflow.log.attachment_render_failed', [
                '%name%' => (string) $invoice->getNumber(),
                '%error%' => $e->getMessage(),
            ]);

            return null;
        }

        return new ResolvedAttachment(
            new MailAttachment($pdf, $baseName.'.pdf', 'application/pdf'),
            $baseName,
            $pdfTemplate,
            $html,
            // Only the hybrid PDF must be preserved byte-for-byte; plain PDFs are
            // re-rendered from the stored HTML on download.
            $asEInvoice ? $pdf : null,
        );
    }

    /**
     * @param Reservation[] $reservations
     *
     * @return Invoice[]
     */
    private function collectInvoices(mixed $entity, array $reservations, bool $onlyOpen): array
    {
        $candidates = [];

        if ($entity instanceof Invoice) {
            $candidates[] = $entity;
        } else {
            foreach ($reservations as $reservation) {
                if (!$reservation instanceof Reservation) {
                    continue;
                }
                foreach ($reservation->getInvoices() as $invoice) {
                    $candidates[] = $invoice;
                }
            }
        }

        $invoices = [];
        foreach ($candidates as $invoice) {
            if (!$invoice instanceof Invoice) {
                continue;
            }
            $status = InvoiceStatus::fromStatus($invoice->getStatus());
            if (InvoiceStatus::CANCELED === $status) {
                continue;
            }
            if ($onlyOpen && InvoiceStatus::OPEN !== $status) {
                continue;
            }
            // Invoices are shared between reservations of a group booking (ManyToMany),
            // so the same invoice would otherwise be attached once per reservation.
            $invoices[$invoice->getId() ?? spl_object_id($invoice)] = $invoice;
        }

        return array_values($invoices);
    }

    /**
     * Makes attachment filenames unique, e.g. "AGB.pdf" and "AGB_2.pdf".
     *
     * @param ResolvedAttachment[] $attachments
     *
     * @return ResolvedAttachment[]
     */
    private function dedupeFilenames(array $attachments): array
    {
        $used = [];
        $result = [];

        foreach ($attachments as $attachment) {
            $name = $attachment->mailAttachment->getName();
            if (!isset($used[$name])) {
                $used[$name] = 1;
                $result[] = $attachment;
                continue;
            }

            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $base = '' !== $extension ? substr($name, 0, -(strlen($extension) + 1)) : $name;
            do {
                ++$used[$name];
                $candidate = $base.'_'.$used[$name].('' !== $extension ? '.'.$extension : '');
            } while (isset($used[$candidate]));

            $used[$candidate] = 1;
            $mail = $attachment->mailAttachment;
            $result[] = $attachment->withMailAttachment(
                new MailAttachment($mail->getBody(), $candidate, $mail->getContentType())
            );
        }

        return $result;
    }

    /**
     * @param ResolvedAttachment[] $attachments
     *
     * @throws WorkflowSkippedException
     */
    private function assertTotalSize(array $attachments): void
    {
        $total = 0;
        foreach ($attachments as $attachment) {
            $total += strlen($attachment->mailAttachment->getBody());
        }

        if ($total > self::MAX_TOTAL_BYTES) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_attachments_too_large', [
                '%size%' => number_format($total / 1024 / 1024, 1),
            ]));
        }
    }
}
