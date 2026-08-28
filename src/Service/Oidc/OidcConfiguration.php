<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use App\Exception\OidcConfigurationException;

/**
 * Immutable snapshot of the OIDC single sign-on settings from the environment.
 *
 * Single sign-on is a system-wide, one-off infrastructure decision (it carries a
 * client secret and has to work before anyone can log in), so it is configured
 * through .env rather than the AppSettings UI — mirroring how passkeys are set up.
 */
final readonly class OidcConfiguration
{
    public function __construct(
        private bool $enabled,
        private string $issuer,
        private string $clientId,
        private string $clientSecret,
        private string $scopes,
        private string $buttonLabel,
        private string $userMatching,
        private bool $requireVerifiedEmail,
        private bool $enforce,
        private bool $endSession,
        private bool $requireHttps = true,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled && '' !== trim($this->issuer) && '' !== trim($this->clientId);
    }

    /**
     * Whether password login is switched off. Deliberately tied to isEnabled():
     * a stray OIDC_ENFORCE=true without a working provider must not lock every
     * user out of the installation.
     */
    public function isEnforced(): bool
    {
        return $this->isEnabled() && $this->enforce;
    }

    public function isEndSessionEnabled(): bool
    {
        return $this->isEnabled() && $this->endSession;
    }

    /**
     * Whether provider endpoints have to be reached over TLS. Fixed to true in
     * prod and relaxed for dev and test, so a local provider without a
     * certificate stays usable.
     */
    public function requiresHttps(): bool
    {
        return $this->requireHttps;
    }

    public function requiresVerifiedEmail(): bool
    {
        return $this->requireVerifiedEmail;
    }

    public function getUserMatching(): OidcUserMatching
    {
        return OidcUserMatching::fromConfig($this->userMatching);
    }

    /**
     * Issuer URL without a trailing slash, so building the discovery URL and
     * comparing against the "iss" claim both stay predictable.
     */
    public function getIssuer(): string
    {
        return rtrim(trim($this->issuer), '/');
    }

    public function getClientId(): string
    {
        return trim($this->clientId);
    }

    public function getClientSecret(): string
    {
        return $this->clientSecret;
    }

    /**
     * Requested scopes. "openid" is always included — without it the provider
     * does not run an OIDC flow at all and returns no ID token.
     *
     * @return list<string>
     */
    public function getScopes(): array
    {
        $scopes = preg_split('/\s+/', trim($this->scopes), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!in_array('openid', $scopes, true)) {
            array_unshift($scopes, 'openid');
        }

        return $scopes;
    }

    /**
     * Operator-provided button caption; empty means the template falls back to
     * the translated default.
     */
    public function getButtonLabel(): string
    {
        return trim($this->buttonLabel);
    }

    /**
     * Fail loudly on a half-finished configuration instead of producing a
     * confusing provider-side error. Called before any outbound request.
     *
     * @throws OidcConfigurationException when a required value is missing
     */
    public function assertConfigured(): void
    {
        if (!$this->enabled) {
            throw new OidcConfigurationException('Single sign-on is disabled (OIDC_ENABLED).');
        }

        foreach (['OIDC_ISSUER' => $this->issuer, 'OIDC_CLIENT_ID' => $this->clientId, 'OIDC_CLIENT_SECRET' => $this->clientSecret] as $name => $value) {
            if ('' === trim($value)) {
                throw new OidcConfigurationException(sprintf('Single sign-on is enabled but %s is empty.', $name));
            }
        }

        $issuer = $this->getIssuer();
        if (!str_starts_with($issuer, 'https://') && !str_starts_with($issuer, 'http://')) {
            throw new OidcConfigurationException('OIDC_ISSUER must be an absolute http(s) URL.');
        }

        // Plain HTTP would expose the client secret and every token in transit.
        // Allowed in dev and test so a local provider without a certificate can
        // be used; the parameter is fixed to true in prod.
        if ($this->requireHttps && !str_starts_with($issuer, 'https://')) {
            throw new OidcConfigurationException('OIDC_ISSUER must use HTTPS.');
        }
    }
}
