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
        $renderInput = $entity;
        if ($entity instanceof Reservation && !empty($context['allReservations'])) {
            $allRes = $context['allReservations'];
            $allAreReservations = array_reduce(
                $allRes,
                static fn (bool $carry, mixed $r) => $carry && $r instanceof Reservation,
                true
            );
            if ($allAreReservations) {
                $renderInput = $allRes;
            }
        }

        $rendered = $this->templatesService->renderTemplate($templateId, $renderInput);
        $subject = $template->getName();

        $this->mailService->sendHTMLMail($recipient, $subject, $rendered);

        // For reservations: persist MailCorrespondence for every reservation in the group
        if ($entity instanceof Reservation) {
            $allRes = !empty($context['allReservations']) ? $context['allReservations'] : [$entity];
            foreach ($allRes as $res) {
                if (!$res instanceof Reservation) {
                    continue;
                }
                $mail = new MailCorrespondence();
                $mail->setRecipient($recipient)
                     ->setName($subject)
                     ->setSubject($subject)
                     ->setText($rendered)
                     ->setTemplate($template)
                     ->setReservation($res);
                $this->em->persist($mail);
            }
            $this->em->flush();
        }

        return $this->translator->trans('workflow.log.email_sent', ['%recipient%' => $recipient, '%template%' => $template->getName()]);
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
