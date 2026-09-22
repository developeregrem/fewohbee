<?php

namespace App\Entity\Enum;

/**
 * Permission scope carried by a personal access token (ApiToken).
 * A scope only takes effect when the token owner also holds the underlying role.
 */
enum ApiScope: string
{
    case RESERVATIONS_READ = 'reservations:read';
    case CALENDAR_READ = 'calendar:read';
    case STATISTICS_READ = 'statistics:read';
    case INVOICES_READ = 'invoices:read';
    case PRICES_READ = 'prices:read';
    case TOURIST_TAX_READ = 'tourist-tax:read';
    case SUBSIDIARIES_READ = 'subsidiaries:read';
    // MCP (AI assistants). mcp:access admits a token to the /mcp endpoint at all; the others
    // are only evaluated by MCP tools for now.
    case MCP_ACCESS = 'mcp:access';
    case GUESTS_READ = 'guests:read';
    case RESERVATIONS_WRITE = 'reservations:write';

    /** The role the token owner needs for the scope to take effect; null when any active user qualifies. */
    public function requiredRole(): ?string
    {
        return match ($this) {
            self::RESERVATIONS_READ => 'ROLE_RESERVATIONS_RO',
            self::CALENDAR_READ => 'ROLE_RESERVATIONS_RO',
            self::STATISTICS_READ => 'ROLE_STATISTICS',
            self::INVOICES_READ => 'ROLE_INVOICES',
            self::PRICES_READ => 'ROLE_RESERVATIONS_RO',
            self::TOURIST_TAX_READ => 'ROLE_OPERATIONS',
            self::SUBSIDIARIES_READ => 'ROLE_RESERVATIONS_RO',
            self::MCP_ACCESS => null,
            self::GUESTS_READ => 'ROLE_CUSTOMERS',
            self::RESERVATIONS_WRITE => 'ROLE_RESERVATIONS',
        };
    }

    /** Whether the scope belongs to the AI assistant (MCP) section of the token form. */
    public function isMcpScope(): bool
    {
        return match ($this) {
            self::MCP_ACCESS, self::GUESTS_READ, self::RESERVATIONS_WRITE => true,
            default => false,
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::RESERVATIONS_READ => 'profile.apitokens.scopes.reservations_read',
            self::CALENDAR_READ => 'profile.apitokens.scopes.calendar_read',
            self::STATISTICS_READ => 'profile.apitokens.scopes.statistics_read',
            self::INVOICES_READ => 'profile.apitokens.scopes.invoices_read',
            self::PRICES_READ => 'profile.apitokens.scopes.prices_read',
            self::TOURIST_TAX_READ => 'profile.apitokens.scopes.tourist_tax_read',
            self::SUBSIDIARIES_READ => 'profile.apitokens.scopes.subsidiaries_read',
            self::MCP_ACCESS => 'profile.apitokens.scopes.mcp_access',
            self::GUESTS_READ => 'profile.apitokens.scopes.guests_read',
            self::RESERVATIONS_WRITE => 'profile.apitokens.scopes.reservations_write',
        };
    }
}
