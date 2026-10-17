<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/** Result of an MCP tool call as recorded in the audit log. */
enum McpToolCallOutcome: string
{
    case OK = 'ok';
    /** The token lacks a scope, the owner lacks the role, or the feature is switched off. */
    case DENIED = 'denied';
    /** The tool refused the input (validation, unavailable room, ...). */
    case INVALID = 'invalid';
    case RATE_LIMITED = 'rate_limited';
    case ERROR = 'error';
}
