<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

/**
 * Weekday and hour restriction shared by every time-based trigger.
 *
 * Config (part of the workflow's triggerConfig):
 *   runOnDays  string – daily | mon_fri | mon_sat
 *   runAtHour  int    – 0-23, the earliest hour of the day the workflow may run
 *
 * Both keys are optional. A workflow saved before this feature existed therefore
 * keeps its old behaviour: every day, at the first cron pass after midnight.
 *
 * Skipped days are never lost. Because the entity triggers match on an exact date,
 * a plain weekday filter would drop the affected records for good (an invoice whose
 * due date falls on a Sunday would never be mailed). Instead the next allowed day
 * catches up: it covers its own target date plus the target dates of the excluded
 * days right before it — see catchUpRange() and coveredDays().
 */
final class ScheduleWindow
{
    public const PRESET_DAILY = 'daily';
    public const PRESET_MON_FRI = 'mon_fri';
    public const PRESET_MON_SAT = 'mon_sat';

    /** @var array<string, list<int>> preset => ISO-8601 weekday numbers (1 = Monday) */
    private const PRESETS = [
        self::PRESET_DAILY => [1, 2, 3, 4, 5, 6, 7],
        self::PRESET_MON_FRI => [1, 2, 3, 4, 5],
        self::PRESET_MON_SAT => [1, 2, 3, 4, 5, 6],
    ];

    /** @param list<int> $allowedWeekdays ISO-8601 weekday numbers (1 = Monday) */
    private function __construct(
        private readonly array $allowedWeekdays,
        private readonly int $hour,
    ) {
    }

    /** @param array<string, mixed> $config a workflow's triggerConfig */
    public static function fromConfig(array $config): self
    {
        $preset = (string) ($config['runOnDays'] ?? self::PRESET_DAILY);
        $weekdays = self::PRESETS[$preset] ?? self::PRESETS[self::PRESET_DAILY];

        $hour = max(0, min(23, (int) ($config['runAtHour'] ?? 0)));

        return new self($weekdays, $hour);
    }

    /**
     * The two config fields every time-based trigger appends to its own schema.
     *
     * @return list<array<string, mixed>>
     */
    public static function configSchema(): array
    {
        return [
            [
                'key' => 'runOnDays',
                'type' => 'select',
                'label' => 'workflow.trigger.run_on_days',
                'help' => 'workflow.trigger.run_on_days_help',
                'default' => self::PRESET_DAILY,
                'options' => [
                    ['value' => self::PRESET_DAILY, 'label' => 'workflow.trigger.run_on_days.daily'],
                    ['value' => self::PRESET_MON_FRI, 'label' => 'workflow.trigger.run_on_days.mon_fri'],
                    ['value' => self::PRESET_MON_SAT, 'label' => 'workflow.trigger.run_on_days.mon_sat'],
                ],
            ],
            [
                // Expanded to a 00:00-23:00 select by WorkflowController::enrichAndTranslateSchema().
                'key' => 'runAtHour',
                'type' => 'hour_select',
                'label' => 'workflow.trigger.run_at_hour',
                'help' => 'workflow.trigger.run_at_hour_help',
                'default' => 0,
            ],
        ];
    }

    public function getHour(): int
    {
        return $this->hour;
    }

    public function isAllowedDay(\DateTimeImmutable $day): bool
    {
        return in_array((int) $day->format('N'), $this->allowedWeekdays, true);
    }

    /**
     * Whether the scheduler may execute the workflow at this very moment.
     *
     * The hour is compared with ">=", not for equality: the cron runs in 15 minute
     * passes, and a missed pass (or an hour that does not exist on a DST change)
     * must not swallow the run. Running twice is prevented by the execution log,
     * not by this check.
     */
    public function shouldRunNow(\DateTimeImmutable $now): bool
    {
        return $this->isAllowedDay($now) && (int) $now->format('G') >= $this->hour;
    }

    /**
     * Number of excluded days directly before $runDay, i.e. how far back this run
     * has to catch up. 0 whenever the previous day was a regular run day.
     */
    public function backlogDays(\DateTimeImmutable $runDay): int
    {
        $backlog = 0;
        $day = $runDay;

        // At most six: with any preset at least one weekday is allowed.
        while ($backlog < 6) {
            $day = $day->modify('-1 day');
            if ($this->isAllowedDay($day)) {
                break;
            }
            ++$backlog;
        }

        return $backlog;
    }

    /**
     * The days $runDay is responsible for: itself plus the excluded days before it,
     * oldest first. Used by triggers that match a day directly instead of a date range.
     *
     * @return list<\DateTimeImmutable>
     */
    public function coveredDays(\DateTimeImmutable $runDay): array
    {
        $days = [];
        for ($offset = $this->backlogDays($runDay); $offset > 0; --$offset) {
            $days[] = $runDay->modify('-' . $offset . ' days');
        }
        $days[] = $runDay;

        return $days;
    }

    /**
     * Widens a trigger's exact target date into the range this run has to cover.
     * Without a backlog both ends are the target date, which is the plain
     * "exactly N days" match the triggers have always used.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [from, to], both inclusive
     */
    public function catchUpRange(\DateTimeImmutable $targetDate, \DateTimeImmutable $runDay): array
    {
        $backlog = $this->backlogDays($runDay);

        return [
            $backlog > 0 ? $targetDate->modify('-' . $backlog . ' days') : $targetDate,
            $targetDate,
        ];
    }

    /** First allowed day at or after $from — the day the next run will happen on. */
    public function nextRunDay(\DateTimeImmutable $from): \DateTimeImmutable
    {
        $day = $from;
        for ($i = 0; $i < 7; ++$i) {
            if ($this->isAllowedDay($day)) {
                return $day;
            }
            $day = $day->modify('+1 day');
        }

        return $from;
    }
}
