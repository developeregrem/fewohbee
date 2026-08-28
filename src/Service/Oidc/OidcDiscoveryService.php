<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use App\Exception\OidcConfigurationException;
use Jose\Component\Core\JWKSet;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches and caches the identity provider's discovery document and signing keys.
 *
 * Both are cached because they are hit on every single sign-on and change rarely.
 * The signing key set can be refreshed on demand so a provider's key rotation
 * does not break logins until the cache expires.
 */
final class OidcDiscoveryService
{
    private const CACHE_TTL = 3600;
    private const HTTP_TIMEOUT = 10;

    public function __construct(
        private readonly OidcConfiguration $config,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $oidcCache,
    ) {
    }

    /**
     * @throws OidcConfigurationException when the provider is unreachable, returns
     *                                    a malformed document, or announces an issuer
     *                                    other than the configured one
     */
    public function getMetadata(): OidcProviderMetadata
    {
        $this->config->assertConfigured();

        $document = $this->oidcCache->get(
            $this->cacheKey('metadata'),
            function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->fetchJson($this->config->getIssuer().'/.well-known/openid-configuration');
            }
        );

        $metadata = OidcProviderMetadata::fromDiscoveryDocument($document);

        // Mix-up defence: a document served under our issuer URL that claims a
        // different issuer means we are talking to the wrong party. Compared on
        // every call, not just on a cache miss.
        // Compared with a trailing slash tolerated, so a small .env typo does
        // not break setup. Token validation later uses the document's value
        // verbatim, which is the one that has to match exactly.
        if (rtrim($metadata->issuer, '/') !== $this->config->getIssuer()) {
            throw new OidcConfigurationException(sprintf(
                'Discovery document announces issuer "%s" but OIDC_ISSUER is "%s".',
                $metadata->issuer,
                $this->config->getIssuer(),
            ));
        }

        $this->assertEndpointsUseTls($metadata);

        return $metadata;
    }

    /**
     * Reject a discovery document that points at plain HTTP endpoints.
     *
     * A conforming provider never does this — OIDC Core mandates TLS for the
     * authorization, token and UserInfo endpoints. In practice it is the
     * signature of a provider sitting behind a proxy that advertises its
     * internal addresses, and the consequences are silent: the client secret
     * and the authorization code would go out in the clear, and a JWKS fetched
     * over plain HTTP can be swapped in transit, which defeats ID token
     * signature verification entirely.
     *
     * @throws OidcConfigurationException when an endpoint we use is not HTTPS
     */
    private function assertEndpointsUseTls(OidcProviderMetadata $metadata): void
    {
        if (!$this->config->requiresHttps()) {
            return;
        }

        $endpoints = [
            'authorization_endpoint' => $metadata->authorizationEndpoint,
            'token_endpoint' => $metadata->tokenEndpoint,
            'jwks_uri' => $metadata->jwksUri,
            'userinfo_endpoint' => $metadata->userInfoEndpoint,
            'end_session_endpoint' => $metadata->endSessionEndpoint,
        ];

        foreach ($endpoints as $name => $url) {
            if (null !== $url && !str_starts_with($url, 'https://')) {
                throw new OidcConfigurationException(sprintf(
                    'The discovery document announces a non-HTTPS %s ("%s"). Check the identity provider\'s public URL configuration.',
                    $name,
                    $url,
                ));
            }
        }
    }

    /**
     * The provider's signing keys.
     *
     * @param bool $forceRefresh bypass the cache — used once when a token carries
     *                           a key id we have not seen, which is what a key
     *                           rotation looks like from here
     *
     * @throws OidcConfigurationException when the key set cannot be fetched or parsed
     */
    public function getJwkSet(bool $forceRefresh = false): JWKSet
    {
        $key = $this->cacheKey('jwks');
        if ($forceRefresh) {
            $this->oidcCache->delete($key);
        }

        $jwks = $this->oidcCache->get($key, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->fetchJson($this->getMetadata()->jwksUri);
        });

        try {
            return JWKSet::createFromKeyData($jwks);
        } catch (\Throwable $e) {
            throw new OidcConfigurationException('The identity provider returned an unusable JWKS document.', 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OidcConfigurationException on a transport error or non-JSON body
     */
    private function fetchJson(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::HTTP_TIMEOUT,
                'headers' => ['Accept' => 'application/json'],
            ]);

            return $response->toArray();
        } catch (\Throwable $e) {
            // The URL is safe to include: it comes from our own configuration,
            // never from user input, and carries no credentials.
            throw new OidcConfigurationException(sprintf('Could not read "%s" from the identity provider.', $url), 0, $e);
        }
    }

    /**
     * Namespaced by issuer so switching providers cannot serve stale metadata.
     */
    private function cacheKey(string $suffix): string
    {
        return 'oidc.'.$suffix.'.'.hash('sha256', $this->config->getIssuer());
    }
}
