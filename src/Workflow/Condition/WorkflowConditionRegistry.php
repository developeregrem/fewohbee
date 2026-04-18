<?php

declare(strict_types=1);

namespace App\Workflow\Condition;

class WorkflowConditionRegistry
{
    /** @var array<string, WorkflowConditionInterface> */
    private array $conditionsByType = [];

    /** @param iterable<WorkflowConditionInterface> $conditions */
    public function __construct(iterable $conditions)
    {
        foreach ($conditions as $condition) {
            $this->conditionsByType[$condition->getType()] = $condition;
        }
    }

    public function get(string $type): WorkflowConditionInterface
    {
        if (!isset($this->conditionsByType[$type])) {
            throw new \InvalidArgumentException(sprintf('Unknown workflow condition type "%s".', $type));
        }

        return $this->conditionsByType[$type];
    }

    public function has(string $type): bool
    {
        return isset($this->conditionsByType[$type]);
    }

    /** @return array<string, WorkflowConditionInterface> */
    public function all(): array
    {
        return $this->conditionsByType;
    }

    /** @return WorkflowConditionInterface[] compatible conditions for a given entity class */
    public function getForEntityClass(string $entityClass): array
    {
        return array_filter(
            $this->conditionsByType,
            fn (WorkflowConditionInterface $c) => in_array($entityClass, $c->getSupportedEntityClasses(), true)
        );
    }

    /** @return array<string, string> label key => type */
    public function getChoices(): array
    {
        $choices = [];
        foreach ($this->conditionsByType as $condition) {
            $choices[$condition->getLabelKey()] = $condition->getType();
        }

        return $choices;
    }
}
