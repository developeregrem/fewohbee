<?php

declare(strict_types=1);

namespace App\Workflow\Action;

use App\Entity\FileCorrespondence;
use App\Entity\Invoice;
use App\Entity\MailAttachment;
use App\Entity\MailCorrespondence;
use App\Entity\Template;
use App\Service\EInvoice\EInvoiceExportService;
use App\Service\EInvoice\EInvoiceReadinessService;
use App\Service\InvoiceService;
use App\Service\MailService;
use App\Service\TemplatesService;
use App\Workflow\Attachment\WorkflowAttachmentResolver;
use App\Workflow\WorkflowSkippedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends an invoice by email with the invoice PDF attached.
 *
 * The attachment is the hybrid PDF with embedded e-invoice XML whenever the
 * invoice passes the profile validation; behaviour is configurable.
 *
 * Config:
 *   templateId      int    – ID of the email template (TEMPLATE_INVOICE_EMAIL)
 *   recipientType   string – invoice_email | custom
 *   customRecipient string – used when recipientType === 'custom'
 *   attachmentMode  string – einvoice_preferred | einvoice_required | pdf_only
 *   attachXml       string – yes | no; additionally attach the raw e-invoice XML (useful for XRechnung)
 *   attachments     array  – extra documents, see WorkflowAttachmentResolver (best-effort;
 *                            "what if it fails" is already covered by attachmentMode)
 */
class SendInvoiceEmailAction implements WorkflowActionInterface
{
    public function __construct(
        private readonly TemplatesService $templatesService,
        private readonly MailService $mailService,
        private readonly InvoiceService $invoiceService,
        private readonly EInvoiceReadinessService $readinessService,
        private readonly EInvoiceExportService $einvoiceExportService,
        private readonly WorkflowAttachmentResolver $attachmentResolver,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getType(): string
    {
        return 'send_invoice_email';
    }

    public function getLabelKey(): string
    {
        return 'workflow.action.send_invoice_email';
    }

    public function getSupportedEntityClasses(): array
    {
        return [Invoice::class];
    }

    public function getSupportedTriggerTypes(): array
    {
        return [];
    }

    public function getConfigSchema(): array
    {
        return [
            [
                'key' => 'templateId',
                'type' => 'template_select',
                'label' => 'workflow.form.template',
                'templateTypes' => ['TEMPLATE_INVOICE_EMAIL'],
            ],
            [
                'key' => 'recipientType',
                'type' => 'select',
                'label' => 'workflow.form.recipient_type',
                'options' => [
                    ['value' => 'invoice_email', 'label' => 'workflow.form.recipient_invoice'],
                    ['value' => 'custom', 'label' => 'workflow.form.recipient_custom'],
                ],
            ],
            [
                'key' => 'customRecipient',
                'type' => 'email',
                'label' => 'workflow.form.custom_recipient',
                'showIf' => ['key' => 'recipientType', 'value' => 'custom'],
            ],
            [
                'key' => 'attachmentMode',
                'type' => 'select',
                'label' => 'workflow.form.attachment_mode',
                'options' => [
                    ['value' => 'einvoice_preferred', 'label' => 'workflow.form.attachment_mode.einvoice_preferred'],
                    ['value' => 'einvoice_required', 'label' => 'workflow.form.attachment_mode.einvoice_required'],
                    ['value' => 'pdf_only', 'label' => 'workflow.form.attachment_mode.pdf_only'],
                ],
            ],
            [
                'key' => 'attachXml',
                'type' => 'select',
                'label' => 'workflow.form.attach_xml',
                'options' => [
                    ['value' => 'no', 'label' => 'workflow.form.attach_xml.no'],
                    ['value' => 'yes', 'label' => 'workflow.form.attach_xml.yes'],
                ],
            ],
            [
                // The invoice itself is already attached above, so only extra documents here.
                'key' => 'attachments',
                'type' => 'attachment_list',
                'label' => 'workflow.form.attachments',
                'help' => 'workflow.form.attachments_help',
                'includeInvoice' => false,
            ],
        ];
    }

    public function execute(array $config, mixed $entity, array $context): string
    {
        if (!$entity instanceof Invoice) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_unsupported_entity'));
        }

        $emailTemplate = $this->resolveEmailTemplate($config);
        $recipient = $this->resolveRecipient($config, $entity);
        if (null === $recipient) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_no_recipient', ['%type%' => $config['recipientType'] ?? 'invoice_email']));
        }

        $pdfTemplate = $this->resolvePdfTemplate();
        $attachments = [];
        $asEInvoice = false;
        $fallbackUsed = false;

        $mode = $config['attachmentMode'] ?? 'einvoice_preferred';
        $settings = $this->readinessService->getActiveSettings();
        $readiness = $this->readinessService->check($entity, $settings);

        if ('einvoice_required' === $mode && !$readiness->ready) {
            $missing = null !== $readiness->result
                ? implode(', ', array_map(fn (string $key): string => $this->translator->trans($key), $readiness->result->getMessageKeys()))
                : $this->translator->trans('invoice.settings.active.error');
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_einvoice_invalid', [
                '%number%' => (string) $entity->getNumber(),
                '%fields%' => $missing,
            ]));
        }

        $pdfBytes = null;
        if ('pdf_only' !== $mode && $readiness->ready) {
            try {
                $pdfBytes = $this->invoiceService->generateInvoicePdfXml($this->templatesService, $this->einvoiceExportService, $entity, $pdfTemplate, $settings);
                $asEInvoice = true;
            } catch (\Throwable $e) {
                if ('einvoice_required' === $mode) {
                    throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_einvoice_invalid', [
                        '%number%' => (string) $entity->getNumber(),
                        '%fields%' => $e->getMessage(),
                    ]));
                }
                $fallbackUsed = true;
            }
        }

        $pdfTemplateOutput = $this->templatesService->renderTemplate($pdfTemplate->getId(), $entity->getId());
        if (null === $pdfBytes) {
            $pdfBytes = $this->templatesService->getPDFOutput($pdfTemplateOutput, $this->invoiceService->buildInvoiceExportFilename($entity), $pdfTemplate);
        }

        $filenameBase = $this->invoiceService->buildInvoiceExportFilename($entity, $asEInvoice);
        $attachments[] = new MailAttachment($pdfBytes, $filenameBase.'.pdf', 'application/pdf');

        if ($asEInvoice && 'yes' === ($config['attachXml'] ?? 'no')) {
            $xml = $this->einvoiceExportService->generateInvoiceData($entity, $settings);
            $attachments[] = new MailAttachment($xml, $filenameBase.'.xml', 'text/xml');
        }

        $reservations = $entity->getReservations()->toArray();
        $extraAttachments = $this->attachmentResolver->resolve(
            is_array($config['attachments'] ?? null) ? $config['attachments'] : [],
            $entity,
            $reservations
        );
        $attachments = array_merge($attachments, $extraAttachments->mailAttachments());

        $rendered = $this->templatesService->renderTemplate($emailTemplate->getId(), $entity);
        try {
            $subject = $this->templatesService->renderTemplateSubject($emailTemplate, $entity);
        } catch (\Throwable $e) {
            // A broken placeholder in the subject must never block the mail.
            $subject = (string) $emailTemplate->getName();
        }

        $this->mailService->sendHTMLMail($recipient, $subject, $rendered, $attachments);

        // Persist the email and the attached invoice file for every linked reservation.
        foreach ($reservations as $reservation) {
            $file = new FileCorrespondence();
            $file->setFileName($filenameBase)
                 ->setName($filenameBase)
                 ->setText($pdfTemplateOutput)
                 ->setTemplate($pdfTemplate)
                 ->setReservation($reservation)
                 ->setBinaryPayload($pdfBytes);
            $this->em->persist($file);

            $mail = new MailCorrespondence();
            $mail->setRecipient($recipient)
                 ->setName($emailTemplate->getName())
                 ->setSubject($subject)
                 ->setText($rendered)
                 ->setTemplate($emailTemplate)
                 ->setReservation($reservation)
                 ->addChild($file);

            foreach ($extraAttachments->attachments as $attachment) {
                $extraFile = $this->attachmentResolver->createFileCorrespondence($attachment, $reservation);
                if (null !== $extraFile) {
                    $this->em->persist($extraFile);
                    $mail->addChild($extraFile);
                }
            }

            $this->em->persist($mail);
        }
        $this->em->flush();

        $logKey = $fallbackUsed ? 'workflow.log.invoice_email_sent_fallback' : 'workflow.log.invoice_email_sent';

        return $this->translator->trans($logKey, [
            '%number%' => (string) $entity->getNumber(),
            '%recipient%' => $recipient,
        ]);
    }

    private function resolveEmailTemplate(array $config): Template
    {
        $templateId = (int) ($config['templateId'] ?? 0);
        if ($templateId <= 0) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_no_template'));
        }

        $template = $this->em->getRepository(Template::class)->find($templateId);
        if (!$template instanceof Template) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_template_not_found', ['%id%' => $templateId]));
        }

        $typeName = $template->getTemplateType()?->getName();
        if ('TEMPLATE_INVOICE_EMAIL' !== $typeName) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_template_incompatible', [
                '%type%' => $typeName ?? 'null',
                '%expected%' => 'TEMPLATE_INVOICE_EMAIL',
            ]));
        }

        return $template;
    }

    private function resolvePdfTemplate(): Template
    {
        $templates = $this->em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_INVOICE_PDF']);
        $default = $this->templatesService->getDefaultTemplate($templates);
        if (!$default instanceof Template) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_no_pdf_template'));
        }

        return $default;
    }

    private function resolveRecipient(array $config, Invoice $invoice): ?string
    {
        $recipientType = $config['recipientType'] ?? 'invoice_email';

        if ('custom' === $recipientType) {
            $custom = trim((string) ($config['customRecipient'] ?? ''));

            return '' !== $custom ? $custom : null;
        }

        $email = trim((string) $invoice->getEmail());

        return '' !== $email ? $email : null;
    }
}
