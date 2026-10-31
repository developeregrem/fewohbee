<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Command\ProcessScheduledWorkflowsCommand;
use App\Entity\Invoice;
use App\Entity\Workflow;
use App\Repository\WorkflowLogRepository;
use App\Repository\WorkflowRepository;
use App\Workflow\Condition\WorkflowConditionRegistry;
use App\Workflow\Trigger\WorkflowTriggerInterface;
use App\Workflow\Trigger\WorkflowTriggerRegistry;
use App\Workflow\WorkflowEngine;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\RequestStack;

final class ProcessScheduledWorkflowsCommandTest extends TestCase
{
    public function testOnlyUnprocessedIdsAreLoadedFromTheTriggersEntityRepository(): void
    {
        $workflow = $this->workflow();
        $invoice = new Invoice();
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('findBy')->with(['id' => [8]])->willReturn([$invoice]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->with(Invoice::class)->willReturn($repository);
        $logs = $this->createMock(WorkflowLogRepository::class);
        $logs->expects(self::once())->method('findProcessedEntityIds')->with(42, Invoice::class)->willReturn([7 => true]);
        $engine = $this->createMock(WorkflowEngine::class);
        $engine->expects(self::once())->method('processScheduledWorkflow')->with($workflow, $invoice)->willReturn(true);
        $trigger = $this->createStub(WorkflowTriggerInterface::class);
        $trigger->method('getType')->willReturn('test.entity');
        $trigger->method('isEventDriven')->willReturn(false);
        $trigger->method('getEntityClass')->willReturn(Invoice::class);
        $trigger->method('findMatchingIds')->willReturn([7, 8]);

        $exit = $this->tester($workflow, $trigger, $em, $logs, $engine)->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
    }

    public function testEntityIdsWithoutAnEntityClassFailBeforeLoadingOrExecuting(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        $engine = $this->createMock(WorkflowEngine::class);
        $engine->expects(self::never())->method('processScheduledWorkflow');
        $trigger = $this->createStub(WorkflowTriggerInterface::class);
        $trigger->method('getType')->willReturn('test.entity');
        $trigger->method('isEventDriven')->willReturn(false);
        $trigger->method('getEntityClass')->willReturn(null);
        $trigger->method('findMatchingIds')->willReturn([7]);
        $tester = $this->tester($this->workflow(), $trigger, $em, $this->createStub(WorkflowLogRepository::class), $engine);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Scheduled trigger "test.entity" returned entity IDs without an entity class.');

        $tester->execute([]);
    }

    private function workflow(): Workflow
    {
        $workflow = new Workflow();
        (new \ReflectionProperty(Workflow::class, 'id'))->setValue($workflow, 42);
        $workflow->setName('Test entity workflow');
        $workflow->setTriggerType('test.entity');
        $workflow->setTriggerConfig(['runOnDays' => 'daily', 'runAtHour' => 0]);
        $workflow->setConditions([]);

        return $workflow;
    }

    private function tester(
        Workflow $workflow,
        WorkflowTriggerInterface $trigger,
        EntityManagerInterface $em,
        WorkflowLogRepository $logs,
        WorkflowEngine $engine,
    ): CommandTester {
        $workflows = $this->createStub(WorkflowRepository::class);
        $workflows->method('findActiveByTriggerTypes')->willReturn([$workflow]);

        return new CommandTester(new ProcessScheduledWorkflowsCommand(
            $workflows,
            $logs,
            new WorkflowTriggerRegistry([$trigger]),
            new WorkflowConditionRegistry([]),
            $engine,
            $em,
            new NullLogger(),
            new RequestStack(),
        ));
    }
}
