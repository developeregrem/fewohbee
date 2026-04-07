<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Workflow;
use App\Repository\WorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates or updates system workflows and example workflows.
 *
 * Idempotent: name and description are always updated to reflect current
 * translations. The is_enabled flag is only set on initial creation so that
 * user-changed toggle states are preserved on subsequent runs.
 */
class WorkflowSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkflowRepository $workflowRepository,
    ) {
    }

    public function seedInternalWorkflows(): void
    {
        $this->createOrUpdateSystem(
            systemCode: 'notify_online_booking',
            name: 'workflow.system.notify_online_booking.name',
            description: 'workflow.system.notify_online_booking.description',
            triggerType: 'online_booking.created',
            actionType: 'send_notification_email',
            defaultEnabled: true,
        );

        $this->createOrUpdateSystem(
            systemCode: 'notify_calendar_import',
            name: 'workflow.system.notify_calendar_import.name',
            description: 'workflow.system.notify_calendar_import.description',
            triggerType: 'calendar_import.created',
            actionType: 'send_notification_email',
            defaultEnabled: true,
        );

        $this->em->flush();
    }

    public function seedExampleWorkflows(): void
    {
        $this->createOrUpdateSystem(
            systemCode: 'example_booking_confirmation',
            name: 'workflow.system.example_booking_confirmation.name',
            description: 'workflow.system.example_booking_confirmation.description',
            triggerType: 'reservation.created',
            actionType: 'send_template_email',
            defaultEnabled: false,
            conditionType: 'reservation.has_booker_email',
            actionConfig: ['recipientType' => 'booker_email', 'templateId' => 0, 'customRecipient' => ''],
        );

        $this->createOrUpdateSystem(
            systemCode: 'example_arrival_reminder',
            name: 'workflow.system.example_arrival_reminder.name',
            description: 'workflow.system.example_arrival_reminder.description',
            triggerType: 'reservation.days_before_start',
            actionType: 'send_template_email',
            defaultEnabled: false,
            conditionType: 'reservation.has_booker_email',
            triggerConfig: ['days' => 3],
            actionConfig: ['recipientType' => 'booker_email', 'templateId' => 0, 'customRecipient' => ''],
        );

        $this->createOrUpdateSystem(
            systemCode: 'example_invoice_reminder',
            name: 'workflow.system.example_invoice_reminder.name',
            description: 'workflow.system.example_invoice_reminder.description',
            triggerType: 'invoice.days_after_date',
            actionType: 'send_template_email',
            defaultEnabled: false,
            conditionType: 'invoice.has_email',
            triggerConfig: ['days' => 14],
            actionConfig: ['recipientType' => 'invoice_email', 'templateId' => 0, 'customRecipient' => ''],
        );

        $this->em->flush();
    }

    /**
     * Create a system workflow if it does not exist yet, or update its name/description
     * if it already exists. The is_enabled flag is only set on initial creation.
     */
    private function createOrUpdateSystem(
        string $systemCode,
        string $name,
        string $description,
        string $triggerType,
        string $actionType,
        bool $defaultEnabled = true,
        ?string $conditionType = null,
        ?array $conditionConfig = null,
        array $actionConfig = [],
        array $triggerConfig = [],
    ): void {
        $existing = $this->workflowRepository->findBySystemCode($systemCode);

        if ($existing instanceof Workflow) {
            // Only update translatable fields; never touch is_enabled (user may have changed it)
            $existing->setName($name);
            $existing->setDescription($description);

            return;
        }

        $workflow = new Workflow();
        $workflow->setName($name);
        $workflow->setDescription($description);
        $workflow->setIsSystem(true);
        $workflow->setSystemCode($systemCode);
        $workflow->setTriggerType($triggerType);
        $workflow->setTriggerConfig($triggerConfig);
        $workflow->setConditionType($conditionType);
        $workflow->setConditionConfig($conditionConfig);
        $workflow->setActionType($actionType);
        $workflow->setActionConfig($actionConfig);
        $workflow->setIsEnabled($defaultEnabled);

        $this->em->persist($workflow);
    }
}
