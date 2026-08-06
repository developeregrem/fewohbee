<?php

declare(strict_types=1);

namespace App\Workflow\Action;

use App\Entity\Invoice;
use App\Entity\MailCorrespondence;
use App\Entity\Reservation;
use App\Entity\Template;
use App\Service\AppSettingsService;
use App\Service\MailService;
use App\Service\TemplatesService;
use App\Workflow\Attachment\WorkflowAttachmentResolver;
use App\Workflow\WorkflowSkippedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends an email using a user-defined template.
 *
 * Supports Reservation and Invoice entities. The template must be of a type
 * compatible with the entity (TEMPLATE_RESERVATION_EMAIL or TEMPLATE_INVOICE_EMAIL).
 *
 * Config:
 *   templateId     int    – ID of the Template to render
 *   recipientType  string – booker_email | invoice_email | notification_email | custom
 *   customRecipient string – used when recipientType === 'custom'
 *   attachments    array  – documents to attach, see WorkflowAttachmentResolver
 *   attachmentPolicy string – skip_missing | require_all
 */
class SendTemplateEmailAction implements WorkflowActionInterface
{
    /** Maps entity class to the compatible template type name. */
    private const ENTITY_TEMPLATE_TYPES = [
        Reservation::class => 'TEMPLATE_RESERVATION_EMAIL',
        Invoice::class => 'TEMPLATE_INVOICE_EMAIL',
    ];

    public function __construct(
        private readonly TemplatesService $templatesService,
        private readonly MailService $mailService,
        private readonly AppSettingsService $settingsService,
        private readonly WorkflowAttachmentResolver $attachmentResolver,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getType(): string
    {
        return 'send_template_email';
    }

    public function getLabelKey(): string
    {
        return 'workflow.action.send_template_email';
    }

    public function getSupportedEntityClasses(): array
    {
        return [Reservation::class, Invoice::class];
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
                'templateTypes' => array_values(self::ENTITY_TEMPLATE_TYPES),
            ],
            [
                'key' => 'recipientType',
                'type' => 'select',
                'label' => 'workflow.form.recipient_type',
                'options' => [
                    ['value' => 'booker_email', 'label' => 'workflow.form.recipient_booker', 'onlyForEntity' => Reservation::class],
                    ['value' => 'invoice_email', 'label' => 'workflow.form.recipient_invoice', 'onlyForEntity' => Invoice::class],
                    ['value' => 'notification_email', 'label' => 'workflow.form.recipient_notification'],
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
                'key' => 'attachments',
                'type' => 'attachment_list',
                'label' => 'workflow.form.attachments',
                'help' => 'workflow.form.attachments_help',
                'includeInvoice' => true,
            ],
            [
                'key' => 'attachmentPolicy',
                'type' => 'select',
                'label' => 'workflow.form.attachment_policy',
                'help' => 'workflow.form.attachment_policy_help',
                // Only relevant once something is actually attached.
                'showIfAny' => 'attachments',
                'default' => WorkflowAttachmentResolver::POLICY_SKIP_MISSING,
                'options' => [
                    ['value' => WorkflowAttachmentResolver::POLICY_SKIP_MISSING, 'label' => 'workflow.form.attachment_policy.skip_missing'],
                    ['value' => WorkflowAttachmentResolver::POLICY_REQUIRE_ALL, 'label' => 'workflow.form.attachment_policy.require_all'],
                ],
            ],
        ];
    }

    public function execute(array $config, mixed $entity, array $context): string
    {
        $templateId = (int) ($config['templateId'] ?? 0);
        if ($templateId <= 0) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_no_template'));
        }

        $template = $this->em->getRepository(Template::class)->find($templateId);
        if (!$template instanceof Template) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_template_not_found', ['%id%' => $templateId]));
        }

        // Verify template type is compatible with entity
        $typeName = $template->getTemplateType()?->getName();
        $expectedType = self::ENTITY_TEMPLATE_TYPES[get_class($entity)] ?? null;
        if (null !== $expectedType && $typeName !== $expectedType) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_template_incompatible', [
                '%type%' => $typeName ?? 'null',
                '%expected%' => $expectedType,
            ]));
        }

        $recipient = $this->resolveRecipient($config, $entity);
        if (null === $recipient || '' === $recipient) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_no_recipient', ['%type%' => $config['recipientType'] ?? 'none']));
        }

        // For reservation templates: pass all reservations from context so the template
        // can render totals/positions across all rooms (e.g. online booking with multiple rooms).
        // resolveReservations() in the preview provider already handles Reservation[].
        $reservationGroup = $this->resolveReservationGroup($entity, $context);
        $renderInput = $entity instanceof Reservation && [] !== $reservationGroup ? $reservationGroup : $entity;

        $rendered = $this->templatesService->renderTemplate($templateId, $renderInput);
        try {
            $subject = $this->templatesService->renderTemplateSubject($template, $renderInput);
        } catch (\Throwable) {
            // A broken placeholder in the subject must never block the mail.
            $subject = (string) $template->getName();
        }

        // Reservations the mail (and its attachments) belong to. For invoices these are
        // the linked reservations, so the correspondence history stays complete.
        $reservations = $entity instanceof Invoice ? $entity->getReservations()->toArray() : $reservationGroup;

        $attachmentSet = $this->attachmentResolver->resolve(
            is_array($config['attachments'] ?? null) ? $config['attachments'] : [],
            $entity,
            $reservations,
            (string) ($config['attachmentPolicy'] ?? WorkflowAttachmentResolver::POLICY_SKIP_MISSING)
        );

        $this->mailService->sendHTMLMail($recipient, $subject, $rendered, $attachmentSet->mailAttachments());

        // Persist the mail and its attachments for every reservation involved.
        foreach ($reservations as $res) {
            if (!$res instanceof Reservation) {
                continue;
            }
            $mail = new MailCorrespondence();
            $mail->setRecipient($recipient)
                 ->setName($template->getName())
                 ->setSubject($subject)
                 ->setText($rendered)
                 ->setTemplate($template)
                 ->setReservation($res);

            foreach ($attachmentSet->attachments as $attachment) {
                $file = $this->attachmentResolver->createFileCorrespondence($attachment, $res);
                if (null !== $file) {
                    $this->em->persist($file);
                    $mail->addChild($file);
                }
            }

            $this->em->persist($mail);
        }
        if ([] !== $reservations) {
            $this->em->flush();
        }

        $summary = $attachmentSet->count() > 0
            ? $this->translator->trans('workflow.log.email_sent_with_attachments', [
                '%recipient%' => $recipient,
                '%template%' => $template->getName(),
                '%count%' => $attachmentSet->count(),
            ])
            : $this->translator->trans('workflow.log.email_sent', [
                '%recipient%' => $recipient,
                '%template%' => $template->getName(),
            ]);

        if ($attachmentSet->hasWarnings()) {
            $summary .= ' – '.$this->translator->trans('workflow.log.attachment_warnings', [
                '%warnings%' => $attachmentSet->warningSummary(),
            ]);
        }

        return $summary;
    }

    /**
     * All reservations a reservation-triggered workflow covers (a booking may span rooms).
     *
     * @return Reservation[]
     */
    private function resolveReservationGroup(mixed $entity, array $context): array
    {
        if (!$entity instanceof Reservation) {
            return [];
        }

        $allRes = $context['allReservations'] ?? [];
        if (is_array($allRes) && [] !== $allRes) {
            $allAreReservations = array_reduce(
                $allRes,
                static fn (bool $carry, mixed $r) => $carry && $r instanceof Reservation,
                true
            );
            if ($allAreReservations) {
                return $allRes;
            }
        }

        return [$entity];
    }

    private function resolveRecipient(array $config, mixed $entity): ?string
    {
        $recipientType = $config['recipientType'] ?? 'notification_email';

        switch ($recipientType) {
            case 'booker_email':
                if (!$entity instanceof Reservation) {
                    return null;
                }
                $booker = $entity->getBooker();
                if (null === $booker) {
                    return null;
                }
                foreach ($booker->getCustomerAddresses() as $address) {
                    $email = $address->getEmail();
                    if (null !== $email && '' !== trim($email)) {
                        return trim($email);
                    }
                }

                return null;

            case 'invoice_email':
                if (!$entity instanceof Invoice) {
                    return null;
                }

                return $entity->getEmail() ?: null;

            case 'notification_email':
                return $this->settingsService->getNotificationEmail() ?: null;

            case 'custom':
                $custom = $config['customRecipient'] ?? '';

                return '' !== trim($custom) ? trim($custom) : null;

            default:
                return null;
        }
    }
}
