<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Workflow\Trigger\MonthlyScheduleTrigger;
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
}
