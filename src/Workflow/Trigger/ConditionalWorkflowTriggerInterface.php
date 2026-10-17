<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

/**
 * A trigger that belongs to an optional feature. While the feature is off it is not offered when
 * creating or editing workflows; existing workflows using it still resolve and stay editable.
 */
interface ConditionalWorkflowTriggerInterface extends WorkflowTriggerInterface
{
    public function isAvailable(): bool;
}
