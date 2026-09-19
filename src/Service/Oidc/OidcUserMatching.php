<?php

declare(strict_types=1);

namespace App\Service\Oidc;

/**
 * How an identity provider account is matched to a local user the first time
 * someone signs in via single sign-on. After that first match the account is
 * bound to the provider's "sub" claim and this setting no longer applies.
 */
enum OidcUserMatching: string
{
    case Email = 'email';
    case Username = 'username';

    /**
     * The OIDC claim carrying the value we compare against the local field.
     */
    public function claim(): string
    {
        return match ($this) {
            self::Email => 'email',
            self::Username => 'preferred_username',
        };
    }

    /**
     * Falls back to matching by e-mail when the configured value is not one of
     * the supported modes — a typo in .env must not silently widen access.
     */
    public static function fromConfig(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Email;
    }
}
