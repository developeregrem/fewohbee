<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

class WorkflowTriggerRegistry
{
    /** @var array<string, WorkflowTriggerInterface> */
    private array $triggersByType = [];

    /** @param iterable<WorkflowTriggerInterface> $triggers */
    public function __construct(iterable $triggers)
    {
        foreach ($triggers as $trigger) {
            $this->triggersByType[$trigger->getType()] = $trigger;
        }
    }

    public function get(string $type): WorkflowTriggerInterface
    {
        if (!isset($this->triggersByType[$type])) {
            throw new \InvalidArgumentException(sprintf('Unknown workflow trigger type "%s".', $type));
        }

        return $this->triggersByType[$type];
    }

    public function has(string $type): bool
    {
        return isset($this->triggersByType[$type]);
    }

    /** @return array<string, WorkflowTriggerInterface> */
    public function all(): array
    {
        return $this->triggersByType;
    }

    /** @return array<string, WorkflowTriggerInterface> */
    public function getEventDriven(): array
    {
        return array_filter($this->triggersByType, fn (WorkflowTriggerInterface $t) => $t->isEventDriven());
    }

    /** @return array<string, WorkflowTriggerInterface> */
    public function getTimeBased(): array
    {
        return array_filter($this->triggersByType, fn (WorkflowTriggerInterface $t) => !$t->isEventDriven());
    }

    /** @return array<string, string> label key => type */
    public function getChoices(): array
    {
        $choices = [];
        foreach ($this->triggersByType as $trigger) {
            $choices[$trigger->getLabelKey()] = $trigger->getType();
        }

        return $choices;
    }
}
