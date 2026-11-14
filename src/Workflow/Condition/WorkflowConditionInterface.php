<?php

declare(strict_types=1);

namespace App\Workflow\Condition;

interface WorkflowConditionInterface
{
    public function getType(): string;

    public function getLabelKey(): string;

    /** @return string[] Entity classes this condition can apply to. */
    public function getSupportedEntityClasses(): array;

    /**
     * Schema describing conditionConfig fields for the UI form builder.
     * Field definitions are ordered; their available keys and values depend on the field type.
     *
     * @return list<array<string, mixed>>
     */
    public function getConfigSchema(): array;

    /**
     * Evaluate the condition against the triggering entity/context.
     *
     * @param array<string, mixed> $config Implementation-specific configuration values
     * @param array<string, mixed> $context Event or scheduler metadata, including entities or reservation groups
     */
    public function evaluate(array $config, mixed $entity, array $context): bool;
}
