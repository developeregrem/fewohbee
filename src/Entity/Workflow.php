<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WorkflowRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: WorkflowRepository::class)]
#[ORM\Table(name: 'workflows')]
#[ORM\Index(name: 'idx_workflow_trigger_type', columns: ['trigger_type'])]
#[ORM\Index(name: 'idx_workflow_system_code', columns: ['system_code'])]
#[ORM\Index(name: 'idx_workflow_enabled', columns: ['is_enabled'])]
#[ORM\HasLifecycleCallbacks]
class Workflow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isEnabled = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isSystem = false;

    #[ORM\Column(type: Types::STRING, length: 80, unique: true, nullable: true)]
    private ?string $systemCode = null;

    #[ORM\Column(type: Types::STRING, length: 80)]
    #[Assert\NotBlank]
    private string $triggerType = '';

    #[ORM\Column(type: Types::JSON)]
    private array $triggerConfig = [];

    /** @var list<array{type: string, config: array}> */
    #[ORM\Column(type: Types::JSON)]
    private array $conditions = [];

    #[ORM\Column(type: Types::STRING, length: 80)]
    #[Assert\NotBlank]
    private string $actionType = '';

    #[ORM\Column(type: Types::JSON)]
    private array $actionConfig = [];

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $priority = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function setIsEnabled(bool $isEnabled): static
    {
        $this->isEnabled = $isEnabled;

        return $this;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function setIsSystem(bool $isSystem): static
    {
        $this->isSystem = $isSystem;

        return $this;
    }

    public function getSystemCode(): ?string
    {
        return $this->systemCode;
    }

    public function setSystemCode(?string $systemCode): static
    {
        $this->systemCode = $systemCode;

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

    public function getTriggerConfig(): array
    {
        return $this->triggerConfig;
    }

    public function setTriggerConfig(array $triggerConfig): static
    {
        $this->triggerConfig = $triggerConfig;

        return $this;
    }

    /** @return list<array{type: string, config: array}> */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    /** @param list<array{type: string, config: array}> $conditions */
    public function setConditions(array $conditions): static
    {
        $this->conditions = $conditions;

        return $this;
    }

    public function getActionType(): string
    {
        return $this->actionType;
    }

    public function setActionType(string $actionType): static
    {
        $this->actionType = $actionType;

        return $this;
    }

    public function getActionConfig(): array
    {
        return $this->actionConfig;
    }

    public function setActionConfig(array $actionConfig): static
    {
        $this->actionConfig = $actionConfig;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
