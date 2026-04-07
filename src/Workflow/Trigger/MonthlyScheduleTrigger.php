<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Fires on a specific day of each month.
 *
 * Config: {"dayOfMonth": 1}
 *
 * The cron command checks whether today's day-of-month matches
 * the configured dayOfMonth. No entity is associated; the workflow
 * executes once per matching day.
 */
class MonthlyScheduleTrigger implements WorkflowTriggerInterface
{
    public function getType(): string
    {
        return 'schedule.monthly';
    }

    public function getLabelKey(): string
    {
        return 'workflow.trigger.schedule_monthly';
    }

    public function getEntityClass(): ?string
    {
        return null;
    }

    public function getConfigSchema(): array
    {
        return [
            [
                'key' => 'dayOfMonth',
                'type' => 'number',
                'label' => 'workflow.trigger.day_of_month',
                'min' => 1,
                'max' => 28,
                'default' => 1,
            ],
        ];
    }

    public function isEventDriven(): bool
    {
        return false;
    }

    public function findPreviewEntities(EntityManagerInterface $em, array $config, int $limit = 20): array
    {
        return [];
    }

    public function findMatchingIds(EntityManagerInterface $em, array $config, int $limit = 500): array
    {
        return [];
    }

    /**
     * Returns true if this trigger should fire today based on the given config.
     */
    public function matchesToday(array $config): bool
    {
        $dayOfMonth = (int) ($config['dayOfMonth'] ?? 1);

        return (int) date('j') === $dayOfMonth;
    }
}
