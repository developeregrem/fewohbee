<?php

declare(strict_types=1);

namespace App\Workflow\Action;

interface WorkflowActionInterface
{
    public function getType(): string;

    public function getLabelKey(): string;

    /** @return string[] Entity classes this action can work with. */
    public function getSupportedEntityClasses(): array;

    /**
     * Restrict this action to specific trigger types.
     * Return an empty array to allow the action for all compatible triggers.
     *
     * @return string[]
     */
    public function getSupportedTriggerTypes(): array;

    /**
     * Schema describing actionConfig fields for the UI form builder.
     * Field definitions are ordered; their available keys and values depend on the field type.
     *
     * @return list<array<string, mixed>>
     */
    public function getConfigSchema(): array;

    /**
     * Execute the action. Returns a summary string for logging.
     *
     * @param array<string, mixed> $config Implementation-specific configuration values
     * @param array<string, mixed> $context Event or scheduler metadata, including entities or reservation groups
     */
    public function execute(array $config, mixed $entity, array $context): string;
}
