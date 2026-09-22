<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Service\Mcp\McpSettings;
use App\Workflow\Trigger\AssistantReservationCreatedTrigger;
use App\Workflow\Trigger\CalendarImportBookingCreatedTrigger;
use App\Workflow\Trigger\WorkflowTriggerInterface;
use App\Workflow\Trigger\WorkflowTriggerRegistry;
use PHPUnit\Framework\TestCase;

final class WorkflowTriggerRegistryOfferedTest extends TestCase
{
    public function testHidesAssistantTriggerWhileMcpIsOff(): void
    {
        $registry = $this->registry(false);

        self::assertNotContains(AssistantReservationCreatedTrigger::TYPE, $this->types($registry->getOffered()));
        self::assertContains('calendar_import.created', $this->types($registry->getOffered()));
        // Existing workflows keep resolving their trigger.
        self::assertTrue($registry->has(AssistantReservationCreatedTrigger::TYPE));
    }

    public function testOffersAssistantTriggerWhileMcpIsOn(): void
    {
        self::assertContains(AssistantReservationCreatedTrigger::TYPE, $this->types($this->registry(true)->getOffered()));
    }

    public function testKeepsTriggerOfEditedWorkflowWhenMcpIsOff(): void
    {
        self::assertContains(
            AssistantReservationCreatedTrigger::TYPE,
            $this->types($this->registry(false)->getOffered(AssistantReservationCreatedTrigger::TYPE))
        );
    }

    private function registry(bool $mcpActive): WorkflowTriggerRegistry
    {
        $settings = $this->createStub(McpSettings::class);
        $settings->method('isActive')->willReturn($mcpActive);

        return new WorkflowTriggerRegistry([
            new CalendarImportBookingCreatedTrigger(),
            new AssistantReservationCreatedTrigger($settings),
        ]);
    }

    /**
     * @param list<WorkflowTriggerInterface> $triggers
     *
     * @return list<string>
     */
    private function types(array $triggers): array
    {
        return array_map(static fn (WorkflowTriggerInterface $trigger): string => $trigger->getType(), $triggers);
    }
}
