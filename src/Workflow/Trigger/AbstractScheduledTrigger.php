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
     * The date range a run has to cover, relative to the day it runs on.
     *
     * $dayOffset is signed: +3 for "three days before arrival", -14 for "fourteen
     * days after the invoice date". Without an excluded weekday before the run day
     * both ends are identical, which is the plain "exactly N days" match.
     *
     * @param array<string, mixed> $config
     * @param bool                 $preview compute the range of the *next* run instead of
     *                                      today's, so the form preview stays truthful when
     *                                      the user edits a workflow on an excluded day
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [from, to], both inclusive
     */
    protected function targetDateRange(array $config, int $dayOffset, bool $preview = false): array
    {
        $window = $this->scheduleWindow($config);
        $today = new \DateTimeImmutable('today');
        $runDay = $preview ? $window->nextRunDay($today) : $today;

        return $window->catchUpRange($runDay->modify(sprintf('%+d days', $dayOffset)), $runDay);
    }
}
