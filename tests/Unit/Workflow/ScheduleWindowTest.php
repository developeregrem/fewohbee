<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Workflow\Trigger\ScheduleWindow;
use PHPUnit\Framework\TestCase;

final class ScheduleWindowTest extends TestCase
{
    private const MONDAY = '2026-08-31';
    private const TUESDAY = '2026-09-01';
    private const SATURDAY = '2026-08-29';
    private const SUNDAY = '2026-08-30';

    private static function day(string $date, string $time = '00:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date . ' ' . $time);
    }

    // -------------------------------------------------------------------------
    // Defaults / backwards compatibility
    // -------------------------------------------------------------------------

    public function testEmptyConfigRunsEveryDayFromMidnight(): void
    {
        $window = ScheduleWindow::fromConfig([]);

        self::assertSame(0, $window->getHour());
        self::assertTrue($window->isAllowedDay(self::day(self::SUNDAY)));
        self::assertTrue($window->shouldRunNow(self::day(self::SUNDAY, '00:05')));
        self::assertSame(0, $window->backlogDays(self::day(self::MONDAY)));
    }

    public function testUnknownPresetFallsBackToDaily(): void
    {
        $window = ScheduleWindow::fromConfig(['runOnDays' => 'whenever']);

        self::assertTrue($window->isAllowedDay(self::day(self::SUNDAY)));
    }

    public function testHourIsClampedToAValidRange(): void
    {
        self::assertSame(23, ScheduleWindow::fromConfig(['runAtHour' => 99])->getHour());
        self::assertSame(0, ScheduleWindow::fromConfig(['runAtHour' => -5])->getHour());
        // The form submits select values as strings.
        self::assertSame(9, ScheduleWindow::fromConfig(['runAtHour' => '9'])->getHour());
    }

    // -------------------------------------------------------------------------
    // Weekday and hour gate
    // -------------------------------------------------------------------------

    public function testMonFriExcludesTheWeekend(): void
    {
        $window = ScheduleWindow::fromConfig(['runOnDays' => ScheduleWindow::PRESET_MON_FRI]);

        self::assertTrue($window->isAllowedDay(self::day(self::MONDAY)));
        self::assertFalse($window->isAllowedDay(self::day(self::SATURDAY)));
        self::assertFalse($window->isAllowedDay(self::day(self::SUNDAY)));
    }

    public function testMonSatExcludesOnlySunday(): void
    {
        $window = ScheduleWindow::fromConfig(['runOnDays' => ScheduleWindow::PRESET_MON_SAT]);

        self::assertTrue($window->isAllowedDay(self::day(self::SATURDAY)));
        self::assertFalse($window->isAllowedDay(self::day(self::SUNDAY)));
    }

    public function testDoesNotRunBeforeTheConfiguredHour(): void
    {
        $window = ScheduleWindow::fromConfig([
            'runOnDays' => ScheduleWindow::PRESET_MON_FRI,
            'runAtHour' => 9,
        ]);

        self::assertFalse($window->shouldRunNow(self::day(self::MONDAY, '08:45')));
        self::assertTrue($window->shouldRunNow(self::day(self::MONDAY, '09:00')));
        // Later passes still qualify, so a missed cron run is caught up the same day.
        self::assertTrue($window->shouldRunNow(self::day(self::MONDAY, '17:30')));
        self::assertFalse($window->shouldRunNow(self::day(self::SUNDAY, '12:00')));
    }

    // -------------------------------------------------------------------------
    // Catch-up
    // -------------------------------------------------------------------------

    public function testMondayCatchesUpForTheWeekend(): void
    {
        $window = ScheduleWindow::fromConfig(['runOnDays' => ScheduleWindow::PRESET_MON_FRI]);

        self::assertSame(2, $window->backlogDays(self::day(self::MONDAY)));
        self::assertSame(0, $window->backlogDays(self::day(self::TUESDAY)));
    }

    public function testMonSatMondayCatchesUpForSundayOnly(): void
    {
        $window = ScheduleWindow::fromConfig(['runOnDays' => ScheduleWindow::PRESET_MON_SAT]);

        self::assertSame(1, $window->backlogDays(self::day(self::MONDAY)));
    }

    public function testCoveredDaysListsTheSkippedDaysOldestFirst(): void
    {
        $window = ScheduleWindow::fromConfig(['runOnDays' => ScheduleWindow::PRESET_MON_FRI]);

        $days = array_map(
            static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'),
            $window->coveredDays(self::day(self::MONDAY))
        );

        self::assertSame([self::SATURDAY, self::SUNDAY, self::MONDAY], $days);
    }

    public function testCatchUpRangeWidensTheTargetDateByTheBacklog(): void
    {
        $window = ScheduleWindow::fromConfig(['runOnDays' => ScheduleWindow::PRESET_MON_FRI]);

        // An invoice reminder 14 days after the invoice date, run on Monday: the
        // due dates of Saturday and Sunday must be picked up as well.
        [$from, $to] = $window->catchUpRange(self::day('2026-08-17'), self::day(self::MONDAY));

        self::assertSame('2026-08-15', $from->format('Y-m-d'));
        self::assertSame('2026-08-17', $to->format('Y-m-d'));
    }

    public function testCatchUpRangeIsASingleDayWithoutBacklog(): void
    {
        $window = ScheduleWindow::fromConfig(['runOnDays' => ScheduleWindow::PRESET_DAILY]);

        [$from, $to] = $window->catchUpRange(self::day('2026-08-17'), self::day(self::MONDAY));

        self::assertSame('2026-08-17', $from->format('Y-m-d'));
        self::assertSame('2026-08-17', $to->format('Y-m-d'));
    }

    public function testConfigSchemaExposesBothFields(): void
    {
        $keys = array_column(ScheduleWindow::configSchema(), 'key');

        self::assertSame(['runOnDays', 'runAtHour'], $keys);
    }
}
