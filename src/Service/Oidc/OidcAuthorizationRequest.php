<?php

declare(strict_types=1);

namespace App\Service\Oidc;

/**
 * A prepared authorization request: the URL the browser is sent to, plus the
 * one-time secrets that must be kept in the session to validate the callback.
 */
final readonly class OidcAuthorizationRequest
{
    public function __construct(
        public string $authorizationUrl,
        public string $state,
        public string $nonce,
        public string $codeVerifier,
        public string $redirectUri,
    ) {
    }
}
