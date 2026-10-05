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
    // Room counts per night without any reservation data, e.g. for a pricing tool.
    case AVAILABILITY_READ = 'availability:read';
    // MCP (AI assistants). mcp:access admits a token to the /mcp endpoint at all; the others
    // are only evaluated by MCP tools for now.
    case MCP_ACCESS = 'mcp:access';
    case GUESTS_READ = 'guests:read';
    case OPERATIONS_READ = 'operations:read';
    case RESERVATIONS_WRITE = 'reservations:write';
    case PRICES_WRITE = 'prices:write';
    case BANK_IMPORT_WRITE = 'bank-import:write';
    case RECEIPTS_SUBMIT = 'receipts:submit';

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
            self::AVAILABILITY_READ => 'ROLE_RESERVATIONS_RO',
            self::MCP_ACCESS => null,
            self::GUESTS_READ => 'ROLE_CUSTOMERS',
            self::OPERATIONS_READ => 'ROLE_OPERATIONS',
            self::RESERVATIONS_WRITE => 'ROLE_RESERVATIONS',
            // Prices are configured in the settings, which only administrators reach.
            self::PRICES_WRITE => 'ROLE_ADMIN',
            self::BANK_IMPORT_WRITE => 'ROLE_CASHJOURNAL',
            self::RECEIPTS_SUBMIT => 'ROLE_CASHJOURNAL',
        };
    }

    /**
     * The question the scope answers in the token form; null for mcp:access, which marks a token
     * as an AI token and is never chosen on its own.
     */
    public function group(): ?ApiScopeGroup
    {
        return match ($this) {
            self::MCP_ACCESS => null,
            self::GUESTS_READ => ApiScopeGroup::PERSONAL_DATA,
            self::RESERVATIONS_WRITE, self::PRICES_WRITE, self::BANK_IMPORT_WRITE, self::RECEIPTS_SUBMIT => ApiScopeGroup::CHANGE,
            default => ApiScopeGroup::SEE,
        };
    }

    /** Whether a REST API endpoint evaluates the scope, so it can be chosen for a REST token. */
    public function isForRest(): bool
    {
        return match ($this) {
            self::MCP_ACCESS, self::GUESTS_READ, self::OPERATIONS_READ, self::RESERVATIONS_WRITE, self::BANK_IMPORT_WRITE, self::RECEIPTS_SUBMIT => false,
            default => true,
        };
    }

    /** Whether an MCP tool evaluates the scope, so it can be chosen for an AI token. */
    public function isForMcp(): bool
    {
        return match ($this) {
            self::MCP_ACCESS, self::CALENDAR_READ, self::SUBSIDIARIES_READ, self::AVAILABILITY_READ => false,
            default => true,
        };
    }

    /** The scope as it appears in the summary sentence of a token ("may read reservations, prices ..."). */
    public function summaryKey(): string
    {
        return 'profile.apitokens.summary.scope.'.strtolower($this->name);
    }

    /** A short note shown under the scope in the token form, or null. */
    public function hintKey(): ?string
    {
        return match ($this) {
            self::RESERVATIONS_WRITE, self::PRICES_WRITE, self::BANK_IMPORT_WRITE, self::RECEIPTS_SUBMIT => 'profile.apitokens.hints.'.strtolower($this->name),
            default => null,
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
            self::AVAILABILITY_READ => 'profile.apitokens.scopes.availability_read',
            self::MCP_ACCESS => 'profile.apitokens.scopes.mcp_access',
            self::GUESTS_READ => 'profile.apitokens.scopes.guests_read',
            self::OPERATIONS_READ => 'profile.apitokens.scopes.operations_read',
            self::RESERVATIONS_WRITE => 'profile.apitokens.scopes.reservations_write',
            self::PRICES_WRITE => 'profile.apitokens.scopes.prices_write',
            self::BANK_IMPORT_WRITE => 'profile.apitokens.scopes.bank_import_write',
            self::RECEIPTS_SUBMIT => 'profile.apitokens.scopes.receipts_submit',
        };
    }
}
