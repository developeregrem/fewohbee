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

    public function requiredRole(): string
    {
        return match ($this) {
            self::RESERVATIONS_READ => 'ROLE_RESERVATIONS_RO',
            self::CALENDAR_READ => 'ROLE_RESERVATIONS_RO',
            self::STATISTICS_READ => 'ROLE_STATISTICS',
            self::INVOICES_READ => 'ROLE_INVOICES',
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::RESERVATIONS_READ => 'profile.apitokens.scopes.reservations_read',
            self::CALENDAR_READ => 'profile.apitokens.scopes.calendar_read',
            self::STATISTICS_READ => 'profile.apitokens.scopes.statistics_read',
            self::INVOICES_READ => 'profile.apitokens.scopes.invoices_read',
        };
    }
}
