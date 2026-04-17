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
        $this->createOrUpdate(
            systemCode: 'notify_online_booking',
            name: 'workflow.system.notify_online_booking.name',
            description: 'workflow.system.notify_online_booking.description',
            triggerType: 'online_booking.created',
            actionType: 'send_notification_email',
            defaultEnabled: true,
            isSystem: true,
        );

        $this->createOrUpdate(
            systemCode: 'notify_calendar_import',
            name: 'workflow.system.notify_calendar_import.name',
            description: 'workflow.system.notify_calendar_import.description',
            triggerType: 'calendar_import.created',
            actionType: 'send_notification_email',
            defaultEnabled: true,
            isSystem: true,
        );

        $this->em->flush();
    }

    public function seedExampleWorkflows(): void
    {
        $this->createOrUpdate(
            systemCode: 'example_booking_confirmation',
            name: 'workflow.system.example_booking_confirmation.name',
            description: 'workflow.system.example_booking_confirmation.description',
            triggerType: 'reservation.created',
            actionType: 'send_template_email',
            defaultEnabled: false,
            conditions: [['type' => 'reservation.has_booker_email', 'config' => []]],
            actionConfig: ['recipientType' => 'booker_email', 'templateId' => 0, 'customRecipient' => ''],
            isSystem: false,
        );

        $this->createOrUpdate(
            systemCode: 'example_arrival_reminder',
            name: 'workflow.system.example_arrival_reminder.name',
            description: 'workflow.system.example_arrival_reminder.description',
            triggerType: 'reservation.days_before_start',
            actionType: 'send_template_email',
            defaultEnabled: false,
            conditions: [['type' => 'reservation.has_booker_email', 'config' => []]],
            triggerConfig: ['days' => 3],
            actionConfig: ['recipientType' => 'booker_email', 'templateId' => 0, 'customRecipient' => ''],
            isSystem: false,
        );

        $this->createOrUpdate(
            systemCode: 'example_invoice_reminder',
            name: 'workflow.system.example_invoice_reminder.name',
            description: 'workflow.system.example_invoice_reminder.description',
            triggerType: 'invoice.days_after_date',
            actionType: 'send_template_email',
            defaultEnabled: false,
            conditions: [['type' => 'invoice.has_email', 'config' => []]],
            triggerConfig: ['days' => 14],
            actionConfig: ['recipientType' => 'invoice_email', 'templateId' => 0, 'customRecipient' => ''],
            isSystem: false,
        );

        $this->createOrUpdate(
            systemCode: 'example_invoice_paid_reservation_status',
            name: 'workflow.system.example_invoice_paid_reservation_status.name',
            description: 'workflow.system.example_invoice_paid_reservation_status.description',
            triggerType: 'invoice.status_changed',
            actionType: 'change_reservation_status',
            defaultEnabled: false,
            conditions: [['type' => 'invoice.status_is', 'config' => ['status' => 2]]],
            actionConfig: ['statusId' => 0],
            isSystem: false,
        );

        $this->em->flush();
    }

    /**
     * Create a system workflow if it does not exist yet, or update its name/description
     * if it already exists. The is_enabled flag is only set on initial creation.
     */
    private function createOrUpdate(
        string $systemCode,
        string $name,
        string $description,
        string $triggerType,
        string $actionType,
        bool $defaultEnabled = true,
        array $conditions = [],
        array $actionConfig = [],
        array $triggerConfig = [],
        bool $isSystem = true,
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
        $workflow->setIsSystem($isSystem);
        $workflow->setSystemCode($systemCode);
        $workflow->setTriggerType($triggerType);
        $workflow->setTriggerConfig($triggerConfig);
        $workflow->setConditions($conditions);
        $workflow->setActionType($actionType);
        $workflow->setActionConfig($actionConfig);
        $workflow->setIsEnabled($defaultEnabled);

        $this->em->persist($workflow);
    }
}
