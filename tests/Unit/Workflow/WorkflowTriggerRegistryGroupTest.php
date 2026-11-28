<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Workflow\Trigger\CalendarImportBookingCreatedTrigger;
use App\Workflow\Trigger\WorkflowTriggerGroup;
use App\Workflow\Trigger\WorkflowTriggerInterface;
use App\Workflow\Trigger\WorkflowTriggerRegistry;
use PHPUnit\Framework\TestCase;

/** The list of automations groups workflows by the group of their trigger. */
final class WorkflowTriggerRegistryGroupTest extends TestCase
{
    public function testAKnownTriggerNamesItsGroup(): void
    {
        $registry = new WorkflowTriggerRegistry([new CalendarImportBookingCreatedTrigger()]);

        self::assertSame(WorkflowTriggerGroup::BOOKINGS, $registry->groupOf('calendar_import.created'));
    }

    public function testATriggerOfItsOwnGroupIsTakenAsItIs(): void
    {
        $trigger = $this->createStub(WorkflowTriggerInterface::class);
        $trigger->method('getType')->willReturn('invoice.example');
        $trigger->method('getGroup')->willReturn(WorkflowTriggerGroup::INVOICES);

        self::assertSame(WorkflowTriggerGroup::INVOICES, (new WorkflowTriggerRegistry([$trigger]))->groupOf('invoice.example'));
    }

    public function testATriggerNoLongerKnownFallsIntoTheLastGroup(): void
    {
        $registry = new WorkflowTriggerRegistry([new CalendarImportBookingCreatedTrigger()]);

        self::assertSame(WorkflowTriggerGroup::OTHER, $registry->groupOf('removed.trigger'));
        self::assertSame(WorkflowTriggerGroup::OTHER, WorkflowTriggerGroup::cases()[array_key_last(WorkflowTriggerGroup::cases())]);
    }
}
