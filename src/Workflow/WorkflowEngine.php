<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Workflow;
use App\Repository\WorkflowRepository;
use App\Workflow\Action\WorkflowActionRegistry;
use App\Workflow\Condition\WorkflowConditionRegistry;
use App\Workflow\Trigger\WorkflowTriggerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class WorkflowEngine
{
    public function __construct(
        private readonly WorkflowRepository $workflowRepository,
        private readonly WorkflowTriggerRegistry $triggerRegistry,
        private readonly WorkflowConditionRegistry $conditionRegistry,
        private readonly WorkflowActionRegistry $actionRegistry,
        private readonly WorkflowLogService $logService,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Process all active workflows for an event-driven trigger.
     *
     * @param object $entity  The entity that caused the event
     * @param array  $context Extra data (e.g. previousStatus, booker)
     */
    public function processEvent(string $triggerType, object $entity, array $context = []): void
    {
        $workflows = $this->workflowRepository->findActiveByTriggerType($triggerType);

        foreach ($workflows as $workflow) {
            // Skip if this workflow already ran successfully for this entity (same dedup as scheduled workflows)
            if ($workflow->getId() !== null && method_exists($entity, 'getId') && $entity->getId() !== null) {
                if ($this->logService->hasBeenProcessed($workflow->getId(), $entity::class, $entity->getId())) {
                    continue;
                }
            }

            $this->executeWorkflow($workflow, $entity, $context, logConditionSkip: true);
        }
    }

    /**
     * Process a single time-based workflow for a specific entity.
     * Includes deduplication check via WorkflowLog.
     * Returns true if the action was executed, false if skipped (dedup or condition not met).
     */
    public function processScheduledWorkflow(Workflow $workflow, object $entity, array $context = []): bool
    {
        if ($workflow->getId() !== null && method_exists($entity, 'getId') && $entity->getId() !== null) {
            if ($this->logService->hasBeenProcessed($workflow->getId(), $entity::class, $entity->getId())) {
                return false;
            }
        }

        return $this->executeWorkflow($workflow, $entity, $context, logConditionSkip: false);
    }

    private function executeWorkflow(Workflow $workflow, mixed $entity, array $context, bool $logConditionSkip = true): bool
    {
        try {
            if ($workflow->getConditionType() !== null) {
                $condition = $this->conditionRegistry->get($workflow->getConditionType());
                if (!$condition->evaluate($workflow->getConditionConfig() ?? [], $entity, $context)) {
                    if ($logConditionSkip) {
                        $this->logService->logSkipped($workflow, $entity, $this->translator->trans('workflow.log.condition_not_met'));
                    }

                    return false;
                }
            }

            $action = $this->actionRegistry->get($workflow->getActionType());
            $summary = $action->execute($workflow->getActionConfig(), $entity, $context);
            $this->logService->logSuccess($workflow, $entity, $summary);

            return true;
        } catch (WorkflowSkippedException $e) {
            $this->logService->logSkipped($workflow, $entity, $e->getMessage());

            return false;
        } catch (\Throwable $e) {
            $this->logService->logError($workflow, $entity, $e->getMessage());
            $workflowName = $workflow->isSystem() ? $this->translator->trans($workflow->getName()) : $workflow->getName();
            $this->logger->error('Workflow execution failed', [
                'workflow_id' => $workflow->getId(),
                'workflow_name' => $workflowName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
