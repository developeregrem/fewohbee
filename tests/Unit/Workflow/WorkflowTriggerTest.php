<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Workflow\Trigger\InvoiceDaysAfterDateTrigger;
use App\Workflow\Trigger\MonthlyScheduleTrigger;
use App\Workflow\Trigger\ReservationDaysAfterEndTrigger;
use App\Workflow\Trigger\ReservationDaysBeforeStartTrigger;
use App\Workflow\Trigger\ScheduleWindow;
use PHPUnit\Framework\TestCase;

final class WorkflowTriggerTest extends TestCase
{
    public function testMatchesTodayReturnsTrueWhenDayMatches(): void
    {
        $trigger = new MonthlyScheduleTrigger();
        $today = (int) date('j');

        self::assertTrue($trigger->matchesToday(['dayOfMonth' => $today]));
    }

    public function testMatchesTodayReturnsFalseWhenDayDoesNotMatch(): void
    {
        $trigger = new MonthlyScheduleTrigger();
        $today = (int) date('j');
        $otherDay = ($today % 28) + 1; // always different, stays in 1-28

        self::assertFalse($trigger->matchesToday(['dayOfMonth' => $otherDay]));
    }

    public function testMatchesTodayDefaultsToDay1WhenConfigMissing(): void
    {
        $trigger = new MonthlyScheduleTrigger();
        $isFirstDay = ((int) date('j')) === 1;

        self::assertSame($isFirstDay, $trigger->matchesToday([]));
    }

    public function testGetTypeReturnsCorrectKey(): void
    {
        $trigger = new MonthlyScheduleTrigger();
        self::assertSame('schedule.monthly', $trigger->getType());
    }

    public function testIsNotEventDriven(): void
    {
        $trigger = new MonthlyScheduleTrigger();
        self::assertFalse($trigger->isEventDriven());
    }

    public function testGetEntityClassReturnsNull(): void
    {
        $trigger = new MonthlyScheduleTrigger();
        self::assertNull($trigger->getEntityClass());
    }

    public function testFindPreviewEntitiesReturnsEmpty(): void
    {
        $trigger = new MonthlyScheduleTrigger();
        $em = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);

        self::assertSame([], $trigger->findPreviewEntities($em, []));
    }

    public function testMatchingDayReturnsTheRunDayItself(): void
    {
        $trigger = new MonthlyScheduleTrigger();
        $monday = new \DateTimeImmutable('2026-08-31 09:00');

        $match = $trigger->matchingDay(['dayOfMonth' => 31], $monday);

        self::assertNotNull($match);
        self::assertSame('2026-08-31', $match->format('Y-m-d'));
    }

    public function testMatchingDayCatchesUpForAnExcludedWeekend(): void
    {
        $trigger = new MonthlyScheduleTrigger();
        $monday = new \DateTimeImmutable('2026-08-31 09:00');

        // The 30th was a Sunday, which Mon-Fri excludes: Monday runs it instead.
        $match = $trigger->matchingDay([
            'dayOfMonth' => 30,
            'runOnDays' => ScheduleWindow::PRESET_MON_FRI,
        ], $monday);

        self::assertNotNull($match);
        self::assertSame('2026-08-30', $match->format('Y-m-d'));
    }

    public function testMatchingDayReturnsNullWhenNoCoveredDayMatches(): void
    {
        $trigger = new MonthlyScheduleTrigger();
        $monday = new \DateTimeImmutable('2026-08-31 09:00');

        self::assertNull($trigger->matchingDay([
            'dayOfMonth' => 15,
            'runOnDays' => ScheduleWindow::PRESET_MON_FRI,
        ], $monday));
    }

    public function testMatchingDayIgnoresTheWeekendWithoutAWeekdayRestriction(): void
    {
        $trigger = new MonthlyScheduleTrigger();
        $monday = new \DateTimeImmutable('2026-08-31 09:00');

        // Running daily, Sunday handled itself — Monday must not repeat it.
        self::assertNull($trigger->matchingDay(['dayOfMonth' => 30], $monday));
    }

    /**
     * @return iterable<string, array{0: \App\Workflow\Trigger\WorkflowTriggerInterface}>
     */
    public static function timeBasedTriggerProvider(): iterable
    {
        yield 'days before start' => [new ReservationDaysBeforeStartTrigger()];
        yield 'days after end' => [new ReservationDaysAfterEndTrigger()];
        yield 'invoice days after date' => [new InvoiceDaysAfterDateTrigger()];
        yield 'monthly' => [new MonthlyScheduleTrigger()];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('timeBasedTriggerProvider')]
    public function testEveryTimeBasedTriggerOffersTheScheduleFields(\App\Workflow\Trigger\WorkflowTriggerInterface $trigger): void
    {
        $keys = array_column($trigger->getConfigSchema(), 'key');

        self::assertContains('runOnDays', $keys);
        self::assertContains('runAtHour', $keys);
        self::assertFalse($trigger->isEventDriven());
    }
}
