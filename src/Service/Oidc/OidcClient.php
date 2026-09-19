<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use App\Exception\OidcConfigurationException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Speaks the OpenID Connect authorization code flow with PKCE against the
 * configured provider: builds the authorization URL and redeems the code at the
 * token endpoint.
 *
 * Only the code flow is implemented. The implicit and hybrid flows return tokens
 * through the browser and are not used here.
 */
final class OidcClient
{
    private const HTTP_TIMEOUT = 10;

    public function __construct(
        private readonly OidcConfiguration $config,
        private readonly OidcDiscoveryService $discovery,
        private readonly HttpClientInterface $httpClient,
        private readonly OidcIdTokenValidator $idTokenValidator,
    ) {
    }

    /**
     * Build the URL the user's browser is redirected to, together with the
     * state, nonce and PKCE verifier the callback has to check against. All
     * three come from a CSPRNG and are single use.
     */
    public function createAuthorizationRequest(string $redirectUri): OidcAuthorizationRequest
    {
        $metadata = $this->discovery->getMetadata();

        $state = self::randomUrlSafeString();
        $nonce = self::randomUrlSafeString();
        $codeVerifier = self::randomUrlSafeString();
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $query = [
            'response_type' => 'code',
            'client_id' => $this->config->getClientId(),
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $this->config->getScopes()),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        $separator = str_contains($metadata->authorizationEndpoint, '?') ? '&' : '?';
        $url = $metadata->authorizationEndpoint.$separator.http_build_query($query, '', '&', \PHP_QUERY_RFC3986);

        return new OidcAuthorizationRequest($url, $state, $nonce, $codeVerifier, $redirectUri);
    }

    /**
     * Redeem an authorization code. The code verifier proves this is the same
     * client that started the flow, which is what stops an intercepted code from
     * being replayed by someone else.
     *
     * @return array<string, mixed> the raw token response; the caller validates the ID token
     *
     * @throws OidcConfigurationException when the token endpoint rejects the request or
     *                                    answers without an ID token
     */
    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri): array
    {
        $metadata = $this->discovery->getMetadata();

        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ];
        $options = ['timeout' => self::HTTP_TIMEOUT, 'headers' => ['Accept' => 'application/json']];

        if (OidcClientAuthMethod::Basic === $metadata->resolveClientAuthMethod()) {
            $options['auth_basic'] = [$this->config->getClientId(), $this->config->getClientSecret()];
        } else {
            $body['client_id'] = $this->config->getClientId();
            $body['client_secret'] = $this->config->getClientSecret();
        }
        $options['body'] = $body;

        try {
            $response = $this->httpClient->request('POST', $metadata->tokenEndpoint, $options);
            $tokens = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new OidcConfigurationException('The token endpoint could not be reached.', 0, $e);
        }

        if (isset($tokens['error'])) {
            // The provider's error code is operator diagnostics only — the
            // authenticator turns this into a generic message for the browser.
            throw new OidcConfigurationException(sprintf(
                'Token endpoint returned an error: %s',
                is_string($tokens['error']) ? $tokens['error'] : 'unknown',
            ));
        }

        if (!isset($tokens['id_token']) || !is_string($tokens['id_token'])) {
            throw new OidcConfigurationException('Token response contains no ID token. Is the "openid" scope allowed for this client?');
        }

        return $tokens;
    }

    /**
     * Fetch the claims the provider serves from its UserInfo endpoint.
     *
     * Many providers — Authelia and Okta among them — keep the ID token to a
     * minimal claim set and expose profile and e-mail claims here instead, so
     * matching a user often depends on this call.
     *
     * The response may be plain JSON or a signed JWS; both are accepted, and in
     * either case the subject is checked against the ID token (OIDC Core 5.3.2)
     * so a response about another user cannot slip through.
     *
     * @return array<string, mixed>
     *
     * @throws OidcConfigurationException when the provider offers no UserInfo endpoint,
     *                                    the call fails, or the subject does not match
     */
    public function fetchUserInfo(string $accessToken, string $expectedSubject): array
    {
        $endpoint = $this->discovery->getMetadata()->userInfoEndpoint;
        if (null === $endpoint) {
            throw new OidcConfigurationException('The identity provider publishes no UserInfo endpoint, so the missing claims cannot be retrieved.');
        }

        try {
            $response = $this->httpClient->request('GET', $endpoint, [
                'timeout' => self::HTTP_TIMEOUT,
                'auth_bearer' => $accessToken,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $body = $response->getContent();
            $contentType = $response->getHeaders()['content-type'][0] ?? '';
        } catch (\Throwable $e) {
            throw new OidcConfigurationException('The UserInfo endpoint could not be reached.', 0, $e);
        }

        if (str_contains($contentType, 'application/jwt')) {
            return $this->idTokenValidator->validateSignedUserInfo($body, $expectedSubject);
        }

        try {
            $claims = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new OidcConfigurationException('The UserInfo endpoint returned neither JSON nor a signed token.', 0, $e);
        }

        if (!is_array($claims)) {
            throw new OidcConfigurationException('The UserInfo response is not a JSON object.');
        }

        $this->idTokenValidator->checkUserInfoSubject($claims, $expectedSubject);

        return $claims;
    }

    /**
     * 43 characters of base64url — satisfies the PKCE verifier length rules and
     * is more than enough entropy for state and nonce as well.
     */
    private static function randomUrlSafeString(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
