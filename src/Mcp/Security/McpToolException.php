<?php

declare(strict_types=1);

namespace App\Mcp\Security;

use App\Entity\Enum\McpToolCallOutcome;

/**
 * Expected refusal of an MCP tool call. The message is sent to the AI client, so it must be safe
 * to disclose: no stack traces, SQL, paths or guest data. ScopedReferenceHandler audits the outcome
 * and converts it into the SDK's ToolCallException.
 */
final class McpToolException extends \RuntimeException
{
    private function __construct(string $message, public readonly McpToolCallOutcome $outcome)
    {
        parent::__construct($message);
    }

    /** Bad input or a business rule refused the call (unavailable room, unknown id, ...). */
    public static function invalid(string $message): self
    {
        return new self($message, McpToolCallOutcome::INVALID);
    }

    /** An administrator switched the capability off. */
    public static function disabled(string $message): self
    {
        return new self($message, McpToolCallOutcome::DENIED);
    }

    public static function rateLimited(string $message): self
    {
        return new self($message, McpToolCallOutcome::RATE_LIMITED);
    }
}
