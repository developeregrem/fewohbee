<?php

declare(strict_types=1);

namespace App\Workflow\Condition;

interface WorkflowConditionInterface
{
    public function getType(): string;

    public function getLabelKey(): string;

    /** @return string[] Entity classes this condition can apply to. */
    public function getSupportedEntityClasses(): array;

    /** Schema describing conditionConfig fields for the UI form builder. */
    public function getConfigSchema(): array;

    /** Evaluate the condition against the triggering entity/context. */
    public function evaluate(array $config, mixed $entity, array $context): bool;
}
