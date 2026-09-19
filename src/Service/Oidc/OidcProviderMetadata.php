<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use App\Exception\OidcConfigurationException;

/**
 * The subset of an identity provider's discovery document that this application
 * uses. Built from <issuer>/.well-known/openid-configuration.
 */
final readonly class OidcProviderMetadata
{
    /**
     * @param list<string> $tokenEndpointAuthMethods
     * @param list<string> $idTokenSigningAlgValues
     */
    public function __construct(
        /** Exactly as the provider published it — never normalised. */
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
        public ?string $userInfoEndpoint,
        public ?string $endSessionEndpoint,
        public array $tokenEndpointAuthMethods,
        public array $idTokenSigningAlgValues,
    ) {
    }

    /**
     * @param array<string, mixed> $document the decoded discovery document
     *
     * @throws OidcConfigurationException when a mandatory endpoint is missing or not a string
     */
    public static function fromDiscoveryDocument(array $document): self
    {
        $required = [];
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
            if (!isset($document[$key]) || !is_string($document[$key]) || '' === $document[$key]) {
                throw new OidcConfigurationException(sprintf('Discovery document is missing "%s".', $key));
            }
            $required[$key] = $document[$key];
        }

        $userInfo = $document['userinfo_endpoint'] ?? null;
        $endSession = $document['end_session_endpoint'] ?? null;

        return new self(
            // Kept verbatim: the issuer is compared by exact string match when
            // validating tokens (OIDC Core 3.1.3.7), so it must not be reshaped.
            $required['issuer'],
            $required['authorization_endpoint'],
            $required['token_endpoint'],
            $required['jwks_uri'],
            is_string($userInfo) && '' !== $userInfo ? $userInfo : null,
            is_string($endSession) && '' !== $endSession ? $endSession : null,
            self::stringList($document['token_endpoint_auth_methods_supported'] ?? null),
            self::stringList($document['id_token_signing_alg_values_supported'] ?? null),
        );
    }

    /**
     * Pick the token endpoint authentication method to use.
     *
     * Providers that omit token_endpoint_auth_methods_supported default to
     * client_secret_basic per RFC 8414. When a provider announces only methods
     * this client cannot perform (private_key_jwt, client_secret_jwt, none),
     * that is reported instead of sending a Basic header the provider will
     * reject with an opaque error.
     *
     * @throws OidcConfigurationException when no supported method is on offer
     */
    public function resolveClientAuthMethod(): OidcClientAuthMethod
    {
        if ([] === $this->tokenEndpointAuthMethods) {
            return OidcClientAuthMethod::Basic;
        }

        foreach ([OidcClientAuthMethod::Basic, OidcClientAuthMethod::Post] as $method) {
            if (in_array($method->value, $this->tokenEndpointAuthMethods, true)) {
                return $method;
            }
        }

        throw new OidcConfigurationException(sprintf(
            'The identity provider accepts none of the supported client authentication methods; it offers: %s.',
            implode(', ', $this->tokenEndpointAuthMethods),
        ));
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
