<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Workflow;
use App\Entity\WorkflowLog;
use App\Repository\WorkflowLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class WorkflowLogService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkflowLogRepository $logRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function logSuccess(Workflow $workflow, mixed $entity, string $message): void
    {
        $this->log($workflow, $entity, 'success', $message);
    }

    public function logSkipped(Workflow $workflow, mixed $entity, string $message): void
    {
        $this->log($workflow, $entity, 'skipped', $message);
    }

    public function logError(Workflow $workflow, mixed $entity, string $message): void
    {
        $this->log($workflow, $entity, 'error', $message);
    }

    public function hasBeenProcessed(int $workflowId, string $entityClass, int $entityId): bool
    {
        return $this->logRepository->hasSuccessfulExecution($workflowId, $entityClass, $entityId);
    }

    private function log(Workflow $workflow, mixed $entity, string $status, string $message): void
    {
        $log = new WorkflowLog();
        $log->setWorkflow($workflow);
        $name = $workflow->isSystem() ? $this->translator->trans($workflow->getName()) : $workflow->getName();
        $log->setWorkflowName($name);
        $log->setTriggerType($workflow->getTriggerType());
        $log->setStatus($status);
        $log->setMessage($message);

        if (is_object($entity) && method_exists($entity, 'getId')) {
            $log->setEntityClass($entity::class);
            $log->setEntityId($entity->getId());
        }

        $this->em->persist($log);
        $this->em->flush();
    }
}
