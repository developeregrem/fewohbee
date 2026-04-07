<?php

declare(strict_types=1);

namespace App\Workflow;

/**
 * Thrown by a WorkflowActionInterface implementation when the action cannot
 * run due to missing or incompatible configuration (e.g. no template selected,
 * no recipient resolvable).
 *
 * The WorkflowEngine catches this and writes a "skipped" log entry instead of
 * a "success" entry. This ensures the deduplication check does NOT block future
 * retries — the action will be attempted again on the next trigger.
 */
class WorkflowSkippedException extends \RuntimeException
{
}
