<?php

declare(strict_types=1);

namespace App\Workflow\Action;

use App\Entity\Template;
use App\Service\AppSettingsService;
use App\Service\MailService;
use App\Service\TemplatesService;
use App\Workflow\WorkflowSkippedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends an email using a TEMPLATE_GENERAL_EMAIL template, without any entity context.
 *
 * Designed for entity-less triggers (e.g. schedule.monthly). The template
 * has no entity variables available; use it for fixed content notifications.
 *
 * Config:
 *   templateId      int    – ID of the Template to render (must be TEMPLATE_GENERAL_EMAIL)
 *   recipientType   string – notification_email | custom
 *   customRecipient string – used when recipientType === 'custom'
 */
class SendGeneralEmailAction implements WorkflowActionInterface
{
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
        return 'send_general_email';
    }

    public function getLabelKey(): string
    {
        return 'workflow.action.send_general_email';
    }

    /**
     * Empty: this action is entity-less and works with any trigger that has no entity class.
     */
    public function getSupportedEntityClasses(): array
    {
        return [];
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
                'templateTypes' => ['TEMPLATE_GENERAL_EMAIL'],
            ],
            [
                'key' => 'recipientType',
                'type' => 'select',
                'label' => 'workflow.form.recipient_type',
                'options' => [
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

        if ($template->getTemplateType()?->getName() !== 'TEMPLATE_GENERAL_EMAIL') {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_template_incompatible', [
                '%type%' => $template->getTemplateType()?->getName() ?? 'null',
                '%expected%' => 'TEMPLATE_GENERAL_EMAIL',
            ]));
        }

        $recipient = $this->resolveRecipient($config);
        if (null === $recipient || '' === $recipient) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_no_recipient', ['%type%' => $config['recipientType'] ?? 'none']));
        }

        // Render without an entity — template has no entity variables
        $rendered = $this->templatesService->renderTemplate($templateId, null);
        $subject = $template->getName();

        $this->mailService->sendHTMLMail($recipient, $subject, $rendered);

        return $this->translator->trans('workflow.log.email_sent', ['%recipient%' => $recipient, '%template%' => $template->getName()]);
    }

    private function resolveRecipient(array $config): ?string
    {
        $recipientType = $config['recipientType'] ?? 'notification_email';

        return match ($recipientType) {
            'notification_email' => $this->settingsService->getNotificationEmail() ?: null,
            'custom' => '' !== trim($config['customRecipient'] ?? '') ? trim($config['customRecipient']) : null,
            default => null,
        };
    }
}
