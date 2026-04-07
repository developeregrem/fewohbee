<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WorkflowLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorkflowLogRepository::class)]
#[ORM\Table(name: 'workflow_logs')]
#[ORM\Index(name: 'idx_wflog_workflow', columns: ['workflow_id'])]
#[ORM\Index(name: 'idx_wflog_executed_at', columns: ['executed_at'])]
#[ORM\Index(name: 'idx_wflog_dedup', columns: ['workflow_id', 'entity_class', 'entity_id', 'status'])]
class WorkflowLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Workflow::class)]
    #[ORM\JoinColumn(name: 'workflow_id', nullable: true, onDelete: 'SET NULL')]
    private ?Workflow $workflow = null;

    #[ORM\Column(type: Types::STRING, length: 150)]
    private string $workflowName = '';

    #[ORM\Column(type: Types::STRING, length: 80)]
    private string $triggerType = '';

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $entityClass = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $entityId = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = 'success';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $executedAt;

    public function __construct()
    {
        $this->executedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWorkflow(): ?Workflow
    {
        return $this->workflow;
    }

    public function setWorkflow(?Workflow $workflow): static
    {
        $this->workflow = $workflow;

        return $this;
    }

    public function getWorkflowName(): string
    {
        return $this->workflowName;
    }

    public function setWorkflowName(string $workflowName): static
    {
        $this->workflowName = $workflowName;

        return $this;
    }

    public function getTriggerType(): string
    {
        return $this->triggerType;
    }

    public function setTriggerType(string $triggerType): static
    {
        $this->triggerType = $triggerType;

        return $this;
    }

    public function getEntityClass(): ?string
    {
        return $this->entityClass;
    }

    public function setEntityClass(?string $entityClass): static
    {
        $this->entityClass = $entityClass;

        return $this;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function setEntityId(?int $entityId): static
    {
        $this->entityId = $entityId;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getExecutedAt(): \DateTimeImmutable
    {
        return $this->executedAt;
    }
}
