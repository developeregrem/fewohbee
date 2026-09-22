<?php

declare(strict_types=1);

namespace App\Mcp\Security;

use App\Entity\Enum\ApiScope;

/**
 * Declares the token scopes an MCP tool needs. Every tool must carry it: ScopedReferenceHandler
 * refuses tools without it (fail closed) and grants a call only when every listed scope passes
 * ApiScopeVoter, i.e. the token carries the scope and its owner holds the underlying role.
 *
 * Scopes that only widen the output (such as guests:read) are not listed here; McpDataFilter
 * evaluates them.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class McpRequiresScope
{
    /** @var list<ApiScope> */
    public readonly array $scopes;

    public function __construct(ApiScope ...$scopes)
    {
        $this->scopes = array_values($scopes);
    }
}
