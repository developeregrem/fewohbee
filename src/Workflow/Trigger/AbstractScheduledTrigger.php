<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

/**
 * Base class for the time-based triggers processed by workflow:process-scheduled.
 *
 * Holds everything the weekday/hour window (see ScheduleWindow) adds to a trigger,
 * so the concrete triggers keep describing nothing but their own criteria.
 */
abstract class AbstractScheduledTrigger implements WorkflowTriggerInterface
{
    public function isEventDriven(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $config a workflow's triggerConfig */
    protected function scheduleWindow(array $config): ScheduleWindow
    {
        return ScheduleWindow::fromConfig($config);
    }

    /**
     * Appends the shared weekday/hour fields to a trigger's own config schema.
     *
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array<string, mixed>>
     */
    protected function withScheduleFields(array $fields): array
    {
        return array_merge($fields, ScheduleWindow::configSchema());
    }

    /**
     * The date range today's run has to cover.
     *
     * $dayOffset is signed: +3 for "three days before arrival", -14 for "fourteen
     * days after the invoice date". Without an excluded weekday before today both
     * ends are identical, which is the plain "exactly N days" match.
     *
     * @param array<string, mixed> $config
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [from, to], both inclusive
     */
    protected function targetDateRange(array $config, int $dayOffset): array
    {
        $window = $this->scheduleWindow($config);
        $today = new \DateTimeImmutable('today');

        return $window->catchUpRange($today->modify(sprintf('%+d days', $dayOffset)), $today);
    }

    /**
     * The date the form preview matches on.
     *
     * Deliberately free of the schedule: the preview answers "which records does this
     * rule cover right now", the weekday and hour settings answer "when does it run".
     * Mixing both would list records whose day has not come yet, on a day the workflow
     * does not even run.
     */
    protected function previewDate(int $dayOffset): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('today'))->modify(sprintf('%+d days', $dayOffset));
    }
}
