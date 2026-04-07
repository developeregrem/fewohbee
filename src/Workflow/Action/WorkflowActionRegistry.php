<?php

declare(strict_types=1);

namespace App\Workflow\Action;

class WorkflowActionRegistry
{
    /** @var array<string, WorkflowActionInterface> */
    private array $actionsByType = [];

    /** @param iterable<WorkflowActionInterface> $actions */
    public function __construct(iterable $actions)
    {
        foreach ($actions as $action) {
            $this->actionsByType[$action->getType()] = $action;
        }
    }

    public function get(string $type): WorkflowActionInterface
    {
        if (!isset($this->actionsByType[$type])) {
            throw new \InvalidArgumentException(sprintf('Unknown workflow action type "%s".', $type));
        }

        return $this->actionsByType[$type];
    }

    public function has(string $type): bool
    {
        return isset($this->actionsByType[$type]);
    }

    /** @return array<string, WorkflowActionInterface> */
    public function all(): array
    {
        return $this->actionsByType;
    }

    /**
     * Returns compatible actions for the given entity class.
     * If $entityClass is null, returns entity-less actions (getSupportedEntityClasses() === []).
     *
     * @return WorkflowActionInterface[]
     */
    public function getForEntityClass(?string $entityClass): array
    {
        if (null === $entityClass) {
            return array_filter(
                $this->actionsByType,
                fn (WorkflowActionInterface $a) => [] === $a->getSupportedEntityClasses()
            );
        }

        return array_filter(
            $this->actionsByType,
            fn (WorkflowActionInterface $a) => in_array($entityClass, $a->getSupportedEntityClasses(), true)
        );
    }

    /** @return array<string, string> label key => type */
    public function getChoices(): array
    {
        $choices = [];
        foreach ($this->actionsByType as $action) {
            $choices[$action->getLabelKey()] = $action->getType();
        }

        return $choices;
    }
}
