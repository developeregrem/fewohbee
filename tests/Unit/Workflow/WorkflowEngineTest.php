<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Entity\Reservation;
use App\Entity\Workflow;
use App\Repository\WorkflowRepository;
use App\Workflow\Action\WorkflowActionInterface;
use App\Workflow\Action\WorkflowActionRegistry;
use App\Workflow\Condition\WorkflowConditionInterface;
use App\Workflow\Condition\WorkflowConditionRegistry;
use App\Workflow\Trigger\WorkflowTriggerRegistry;
use App\Workflow\WorkflowEngine;
use App\Workflow\WorkflowLogService;
use App\Workflow\WorkflowSkippedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WorkflowEngineTest extends TestCase
{
    private function makeWorkflow(
        string $triggerType = 'reservation.created',
        string $actionType = 'send_notification_email',
        array $conditions = [],
        bool $enabled = true,
    ): Workflow {
        $wf = new Workflow();
        $wf->setName('Test Workflow');
        $wf->setTriggerType($triggerType);
        $wf->setActionType($actionType);
        $wf->setConditions($conditions);
        $wf->setIsEnabled($enabled);
        $wf->setActionConfig([]);
        $wf->setTriggerConfig([]);

        return $wf;
    }

    private function makeEngine(
        array $workflows,
        WorkflowActionInterface $action,
        ?WorkflowConditionInterface $condition = null,
        ?WorkflowLogService $logService = null,
    ): WorkflowEngine {
        $workflowRepo = $this->createStub(WorkflowRepository::class);
        $workflowRepo->method('findActiveByTriggerType')->willReturn($workflows);

        $actionRegistry = $this->createStub(WorkflowActionRegistry::class);
        $actionRegistry->method('get')->willReturn($action);

        $conditionRegistry = $this->createStub(WorkflowConditionRegistry::class);
        if ($condition !== null) {
            $conditionRegistry->method('get')->willReturn($condition);
        }

        $triggerRegistry = $this->createStub(WorkflowTriggerRegistry::class);
        $logService ??= $this->createStub(WorkflowLogService::class);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new WorkflowEngine(
            $workflowRepo,
            $triggerRegistry,
            $conditionRegistry,
            $actionRegistry,
            $logService,
            new NullLogger(),
            $translator,
        );
    }

    public function testProcessEventExecutesAction(): void
    {
        $entity = $this->createStub(Reservation::class);
        $workflow = $this->makeWorkflow();

        $action = $this->createMock(WorkflowActionInterface::class);
        $action->expects(self::once())
            ->method('execute')
            ->with([], $entity, [])
            ->willReturn('Email sent');

        $logService = $this->createMock(WorkflowLogService::class);
        $logService->expects(self::once())->method('logSuccess');

        $engine = $this->makeEngine([$workflow], $action, null, $logService);
        $engine->processEvent('reservation.created', $entity);
    }

    public function testProcessEventSkipsWhenConditionNotMet(): void
    {
        $entity = $this->createStub(Reservation::class);
        $workflow = $this->makeWorkflow(conditions: [['type' => 'reservation.has_booker_email', 'config' => []]]);

        $action = $this->createMock(WorkflowActionInterface::class);
        $action->expects(self::never())->method('execute');

        $condition = $this->createStub(WorkflowConditionInterface::class);
        $condition->method('evaluate')->willReturn(false);

        $logService = $this->createMock(WorkflowLogService::class);
        $logService->expects(self::once())->method('logSkipped');

        $engine = $this->makeEngine([$workflow], $action, $condition, $logService);
        $engine->processEvent('reservation.created', $entity);
    }

    public function testProcessEventExecutesWhenConditionMet(): void
    {
        $entity = $this->createStub(Reservation::class);
        $workflow = $this->makeWorkflow(conditions: [['type' => 'reservation.has_booker_email', 'config' => []]]);

        $action = $this->createMock(WorkflowActionInterface::class);
        $action->expects(self::once())->method('execute')->willReturn('done');

        $condition = $this->createStub(WorkflowConditionInterface::class);
        $condition->method('evaluate')->willReturn(true);

        $engine = $this->makeEngine([$workflow], $action, $condition);
        $engine->processEvent('reservation.created', $entity);
    }

    public function testProcessEventSkipsWhenSecondConditionNotMet(): void
    {
        $entity = $this->createStub(Reservation::class);
        $workflow = $this->makeWorkflow(conditions: [
            ['type' => 'cond_a', 'config' => []],
            ['type' => 'cond_b', 'config' => []],
        ]);

        $action = $this->createMock(WorkflowActionInterface::class);
        $action->expects(self::never())->method('execute');

        $condA = $this->createStub(WorkflowConditionInterface::class);
        $condA->method('evaluate')->willReturn(true);
        $condB = $this->createStub(WorkflowConditionInterface::class);
        $condB->method('evaluate')->willReturn(false);

        $conditionRegistry = $this->createStub(WorkflowConditionRegistry::class);
        $conditionRegistry->method('get')->willReturnMap([
            ['cond_a', $condA],
            ['cond_b', $condB],
        ]);

        $workflowRepo = $this->createStub(WorkflowRepository::class);
        $workflowRepo->method('findActiveByTriggerType')->willReturn([$workflow]);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $logService = $this->createMock(WorkflowLogService::class);
        $logService->expects(self::once())->method('logSkipped');

        $engine = new WorkflowEngine(
            $workflowRepo,
            $this->createStub(WorkflowTriggerRegistry::class),
            $conditionRegistry,
            $this->createStub(WorkflowActionRegistry::class),
            $logService,
            new NullLogger(),
            $translator,
        );

        $engine->processEvent('reservation.created', $entity);
    }

    public function testProcessEventExecutesWhenAllConditionsMet(): void
    {
        $entity = $this->createStub(Reservation::class);
        $workflow = $this->makeWorkflow(conditions: [
            ['type' => 'cond_a', 'config' => []],
            ['type' => 'cond_b', 'config' => []],
        ]);

        $action = $this->createMock(WorkflowActionInterface::class);
        $action->expects(self::once())->method('execute')->willReturn('done');

        $condA = $this->createStub(WorkflowConditionInterface::class);
        $condA->method('evaluate')->willReturn(true);
        $condB = $this->createStub(WorkflowConditionInterface::class);
        $condB->method('evaluate')->willReturn(true);

        $conditionRegistry = $this->createStub(WorkflowConditionRegistry::class);
        $conditionRegistry->method('get')->willReturnMap([
            ['cond_a', $condA],
            ['cond_b', $condB],
        ]);

        $workflowRepo = $this->createStub(WorkflowRepository::class);
        $workflowRepo->method('findActiveByTriggerType')->willReturn([$workflow]);

        $actionRegistry = $this->createStub(WorkflowActionRegistry::class);
        $actionRegistry->method('get')->willReturn($action);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $engine = new WorkflowEngine(
            $workflowRepo,
            $this->createStub(WorkflowTriggerRegistry::class),
            $conditionRegistry,
            $actionRegistry,
            $this->createStub(WorkflowLogService::class),
            new NullLogger(),
            $translator,
        );

        $engine->processEvent('reservation.created', $entity);
    }

    public function testProcessEventLogsErrorOnException(): void
    {
        $entity = $this->createStub(Reservation::class);
        $workflow = $this->makeWorkflow();

        $action = $this->createStub(WorkflowActionInterface::class);
        $action->method('execute')->willThrowException(new \RuntimeException('Mail server down'));

        $logService = $this->createMock(WorkflowLogService::class);
        $logService->expects(self::once())->method('logError');

        $engine = $this->makeEngine([$workflow], $action, null, $logService);
        $engine->processEvent('reservation.created', $entity);
    }

    public function testProcessEventHandlesMultipleWorkflows(): void
    {
        $entity = $this->createStub(Reservation::class);
        $wf1 = $this->makeWorkflow();
        $wf2 = $this->makeWorkflow();

        $action = $this->createMock(WorkflowActionInterface::class);
        $action->expects(self::exactly(2))->method('execute')->willReturn('done');

        $workflowRepo = $this->createStub(WorkflowRepository::class);
        $workflowRepo->method('findActiveByTriggerType')->willReturn([$wf1, $wf2]);

        $actionRegistry = $this->createStub(WorkflowActionRegistry::class);
        $actionRegistry->method('get')->willReturn($action);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $engine = new WorkflowEngine(
            $workflowRepo,
            $this->createStub(WorkflowTriggerRegistry::class),
            $this->createStub(WorkflowConditionRegistry::class),
            $actionRegistry,
            $this->createStub(WorkflowLogService::class),
            new NullLogger(),
            $translator,
        );

        $engine->processEvent('reservation.created', $entity);
    }

    public function testOneFailingWorkflowDoesNotBlockOthers(): void
    {
        $entity = $this->createStub(Reservation::class);
        $wf1 = $this->makeWorkflow(actionType: 'failing');
        $wf2 = $this->makeWorkflow(actionType: 'succeeding');

        $failingAction = $this->createStub(WorkflowActionInterface::class);
        $failingAction->method('execute')->willThrowException(new \RuntimeException('fail'));

        $succeedingAction = $this->createStub(WorkflowActionInterface::class);
        $succeedingAction->method('execute')->willReturn('ok');

        $workflowRepo = $this->createStub(WorkflowRepository::class);
        $workflowRepo->method('findActiveByTriggerType')->willReturn([$wf1, $wf2]);

        $actionRegistry = $this->createStub(WorkflowActionRegistry::class);
        $actionRegistry->method('get')->willReturnMap([
            ['failing', $failingAction],
            ['succeeding', $succeedingAction],
        ]);

        $logService = $this->createMock(WorkflowLogService::class);
        $logService->expects(self::once())->method('logError');
        $logService->expects(self::once())->method('logSuccess');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $engine = new WorkflowEngine(
            $workflowRepo,
            $this->createStub(WorkflowTriggerRegistry::class),
            $this->createStub(WorkflowConditionRegistry::class),
            $actionRegistry,
            $logService,
            new NullLogger(),
            $translator,
        );

        $engine->processEvent('reservation.created', $entity);
    }

    public function testProcessEventSkipsDuplicates(): void
    {
        $entity = $this->createStub(Reservation::class);
        $entity->method('getId')->willReturn(42);

        $workflow = $this->makeWorkflow();
        $ref = new \ReflectionProperty(Workflow::class, 'id');
        $ref->setValue($workflow, 1);

        $action = $this->createMock(WorkflowActionInterface::class);
        $action->expects(self::never())->method('execute');

        $logService = $this->createStub(WorkflowLogService::class);
        $logService->method('hasBeenProcessed')->willReturn(true);

        $workflowRepo = $this->createStub(WorkflowRepository::class);
        $workflowRepo->method('findActiveByTriggerType')->willReturn([$workflow]);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $actionRegistry = $this->createStub(WorkflowActionRegistry::class);

        $engine = new WorkflowEngine(
            $workflowRepo,
            $this->createStub(WorkflowTriggerRegistry::class),
            $this->createStub(WorkflowConditionRegistry::class),
            $actionRegistry,
            $logService,
            new NullLogger(),
            $translator,
        );

        $engine->processEvent('reservation.created', $entity);
    }

    public function testProcessScheduledWorkflowSkipsDuplicates(): void
    {
        $entity = $this->createStub(Reservation::class);
        $entity->method('getId')->willReturn(42);

        $workflow = $this->makeWorkflow();

        $action = $this->createMock(WorkflowActionInterface::class);
        $action->expects(self::never())->method('execute');

        $logService = $this->createStub(WorkflowLogService::class);
        $logService->method('hasBeenProcessed')->willReturn(true);

        $workflowRepo = $this->createStub(WorkflowRepository::class);
        $actionRegistry = $this->createStub(WorkflowActionRegistry::class);

        // We need a real workflow ID for dedup check — simulate via reflection
        $ref = new \ReflectionProperty(Workflow::class, 'id');
        $ref->setValue($workflow, 1);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $engine = new WorkflowEngine(
            $workflowRepo,
            $this->createStub(WorkflowTriggerRegistry::class),
            $this->createStub(WorkflowConditionRegistry::class),
            $actionRegistry,
            $logService,
            new NullLogger(),
            $translator,
        );

        $engine->processScheduledWorkflow($workflow, $entity);
    }

    public function testProcessEventLogsSkippedWhenActionThrowsWorkflowSkippedException(): void
    {
        $entity = $this->createStub(Reservation::class);
        $workflow = $this->makeWorkflow();

        $action = $this->createStub(WorkflowActionInterface::class);
        $action->method('execute')->willThrowException(new WorkflowSkippedException('Template not configured'));

        $logService = $this->createMock(WorkflowLogService::class);
        $logService->expects(self::once())->method('logSkipped')->with($workflow, $entity, 'Template not configured');
        $logService->expects(self::never())->method('logSuccess');
        $logService->expects(self::never())->method('logError');

        $engine = $this->makeEngine([$workflow], $action, null, $logService);
        $engine->processEvent('reservation.created', $entity);
    }

    public function testProcessScheduledWorkflowDoesNotLogSkippedWhenConditionNotMet(): void
    {
        $entity = $this->createStub(Reservation::class);
        $entity->method('getId')->willReturn(99);

        $workflow = $this->makeWorkflow(conditions: [['type' => 'reservation.has_booker_email', 'config' => []]]);

        $ref = new \ReflectionProperty(Workflow::class, 'id');
        $ref->setValue($workflow, 1);

        $action = $this->createMock(WorkflowActionInterface::class);
        $action->expects(self::never())->method('execute');

        $condition = $this->createStub(WorkflowConditionInterface::class);
        $condition->method('evaluate')->willReturn(false);

        $logService = $this->createMock(WorkflowLogService::class);
        $logService->method('hasBeenProcessed')->willReturn(false);
        $logService->expects(self::never())->method('logSkipped');

        $engine = $this->makeEngine([$workflow], $action, $condition, $logService);
        $engine->processScheduledWorkflow($workflow, $entity);
    }
}
