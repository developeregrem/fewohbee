<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Fires on a specific day of each month.
 *
 * Config: {"dayOfMonth": 1, "runOnDays": "mon_fri", "runAtHour": 9}
 *
 * The cron command checks whether the configured day-of-month falls on today or on
 * one of the excluded weekdays this run catches up for. No entity is associated;
 * the workflow executes once per matching day.
 */
class MonthlyScheduleTrigger extends AbstractScheduledTrigger
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
        return $this->withScheduleFields([
            [
                'key' => 'dayOfMonth',
                'type' => 'number',
                'label' => 'workflow.trigger.day_of_month',
                'min' => 1,
                'max' => 28,
                'default' => 1,
            ],
        ]);
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
     * The day this run fires for: today, or the most recent excluded day whose
     * day-of-month matches and which today catches up for. Null when the workflow
     * has nothing to do.
     *
     * The caller needs the day itself, not just a boolean: with no entity to
     * deduplicate against it is the only thing that keeps the 15 minute cron
     * passes from executing the same monthly occurrence over and over.
     *
     * @param array<string, mixed> $config
     */
    public function matchingDay(array $config, ?\DateTimeImmutable $now = null): ?\DateTimeImmutable
    {
        $dayOfMonth = (int) ($config['dayOfMonth'] ?? 1);
        $runDay = ($now ?? new \DateTimeImmutable())->setTime(0, 0, 0);

        foreach ($this->scheduleWindow($config)->coveredDays($runDay) as $day) {
            if ((int) $day->format('j') === $dayOfMonth) {
                return $day;
            }
        }

        return null;
    }

    /**
     * Returns true if this trigger should fire today based on the given config.
     *
     * @param array<string, mixed> $config
     */
    public function matchesToday(array $config): bool
    {
        return null !== $this->matchingDay($config);
    }
}
