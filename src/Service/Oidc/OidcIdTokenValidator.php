<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use App\Exception\OidcConfigurationException;
use Jose\Component\Checker\ClaimCheckerManager;
use Jose\Component\Checker\ExpirationTimeChecker;
use Jose\Component\Checker\IssuedAtChecker;
use Jose\Component\Checker\IssuerChecker;
use Jose\Component\Checker\NotBeforeChecker;
use Jose\Component\Core\Algorithm;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\ES384;
use Jose\Component\Signature\Algorithm\ES512;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\Algorithm\PS384;
use Jose\Component\Signature\Algorithm\PS512;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Psr\Clock\ClockInterface;

/**
 * Verifies an ID token: signature first, then the claims that tie the token to
 * this client and this login attempt.
 *
 * Only asymmetric signatures are accepted. "none" is rejected because it is the
 * classic JWT forgery, and HMAC is rejected because with a shared client secret
 * anyone who can read the configuration could mint their own tokens.
 */
final class OidcIdTokenValidator
{
    /** Tolerance for clock drift between this host and the provider, in seconds. */
    private const CLOCK_SKEW = 60;

    public function __construct(
        private readonly OidcConfiguration $config,
        private readonly OidcDiscoveryService $discovery,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param string $expectedNonce the nonce generated when the flow started
     *
     * @return array<string, mixed> the validated claims
     *
     * @throws OidcConfigurationException when the token is malformed, unsigned by a key we
     *                                    trust, or fails any claim check
     */
    public function validate(string $idToken, string $expectedNonce): array
    {
        $claims = $this->verifyAndDecode($idToken, 'ID token');
        $this->checkClaims($claims);
        $this->checkNonce($claims, $expectedNonce);
        $this->checkAuthorizedParty($claims);

        return $claims;
    }

    /**
     * Verify a signed UserInfo response.
     *
     * Providers may return UserInfo as a JWS instead of plain JSON. It carries
     * no nonce and need not carry exp, so only the signature, the issuer and
     * the subject are checked — the subject binding is what stops a response
     * about a different user from being accepted (OIDC Core 5.3.2).
     *
     * @return array<string, mixed>
     *
     * @throws OidcConfigurationException when the signature or the subject does not hold up
     */
    public function validateSignedUserInfo(string $jwt, string $expectedSubject): array
    {
        $claims = $this->verifyAndDecode($jwt, 'UserInfo response');

        // OIDC Core 5.3.2 says a signed response SHOULD carry iss and aud. We
        // require them: without both, a signed blob from the same provider for
        // a different client would be indistinguishable from one meant for us.
        $issuer = $claims['iss'] ?? null;
        if (!is_string($issuer) || !hash_equals($this->discovery->getMetadata()->issuer, $issuer)) {
            throw new OidcConfigurationException('The signed UserInfo response carries no matching "iss" claim.');
        }

        if (!isset($claims['aud'])) {
            throw new OidcConfigurationException('The signed UserInfo response carries no "aud" claim.');
        }
        $this->checkAudience($claims);

        $this->checkUserInfoSubject($claims, $expectedSubject);

        return $claims;
    }

    /**
     * The subject in a UserInfo response must match the one from the ID token,
     * or the provider is telling us about somebody else.
     *
     * @param array<string, mixed> $claims
     */
    public function checkUserInfoSubject(array $claims, string $expectedSubject): void
    {
        $subject = $claims['sub'] ?? null;
        if (!is_string($subject) || !hash_equals($expectedSubject, $subject)) {
            throw new OidcConfigurationException('The UserInfo response belongs to a different subject than the ID token.');
        }
    }

    /**
     * Shared JWS handling: structure, algorithm whitelist, signature and JSON
     * payload. Claim semantics are left to the caller.
     *
     * @param string $what names the token in error messages
     *
     * @return array<string, mixed>
     */
    private function verifyAndDecode(string $token, string $what): array
    {
        try {
            $jws = (new CompactSerializer())->unserialize($token);
        } catch (\Throwable $e) {
            throw new OidcConfigurationException(sprintf('The %s is not a well-formed JWS.', $what), 0, $e);
        }

        if (1 !== $jws->countSignatures()) {
            throw new OidcConfigurationException(sprintf('The %s must carry exactly one signature.', $what));
        }

        $header = $jws->getSignature(0)->getProtectedHeader();
        $alg = $header['alg'] ?? null;
        if (!is_string($alg) || !isset(self::supportedAlgorithms()[$alg])) {
            throw new OidcConfigurationException(sprintf('Unsupported %s signature algorithm "%s".', $what, is_string($alg) ? $alg : 'none given'));
        }

        $algorithm = self::supportedAlgorithms()[$alg];
        $kid = isset($header['kid']) && is_string($header['kid']) ? $header['kid'] : null;

        if (!$this->verifySignature($jws, $algorithm, $kid, false)
            // An unknown key id is what a provider key rotation looks like from
            // here, so refetch the key set once before giving up.
            && !$this->verifySignature($jws, $algorithm, $kid, true)) {
            throw new OidcConfigurationException(sprintf('The %s signature could not be verified against the provider key set.', $what));
        }

        return $this->decodeClaims($jws->getPayload());
    }

    /**
     * @param \Jose\Component\Signature\JWS $jws
     */
    private function verifySignature(object $jws, Algorithm $algorithm, ?string $kid, bool $forceRefresh): bool
    {
        try {
            $keySet = $this->discovery->getJwkSet($forceRefresh);
        } catch (OidcConfigurationException $e) {
            // On the first attempt an unreachable provider is the real problem
            // and must surface. On the rotation retry it would only mask the
            // signature mismatch that sent us here.
            if (!$forceRefresh) {
                throw $e;
            }

            return false;
        }

        // Restricting by kid keeps a provider that publishes several keys from
        // having any of them accepted for a token that names one.
        $restrictions = null !== $kid ? ['kid' => $kid] : [];
        $key = $keySet->selectKey('sig', $algorithm, $restrictions);
        if (null === $key) {
            return false;
        }

        $verifier = new JWSVerifier(new AlgorithmManager([$algorithm]));

        return $verifier->verifyWithKey($jws, $key, 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeClaims(?string $payload): array
    {
        if (null === $payload) {
            throw new OidcConfigurationException('The token has an empty payload.');
        }

        try {
            $claims = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new OidcConfigurationException('The token payload is not valid JSON.', 0, $e);
        }

        if (!is_array($claims)) {
            throw new OidcConfigurationException('The token payload is not a JSON object.');
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function checkClaims(array $claims): void
    {
        $manager = new ClaimCheckerManager([
            // Compared verbatim against the value the provider published in its
            // discovery document; issuers are matched by exact string.
            new IssuerChecker([$this->discovery->getMetadata()->issuer]),
            new ExpirationTimeChecker($this->clock, self::CLOCK_SKEW),
            new IssuedAtChecker($this->clock, self::CLOCK_SKEW),
            new NotBeforeChecker($this->clock, self::CLOCK_SKEW),
        ]);

        try {
            $manager->check($claims, ['iss', 'aud', 'exp', 'iat', 'sub']);
        } catch (\Throwable $e) {
            throw new OidcConfigurationException(sprintf('ID token claim check failed: %s', $e->getMessage()), 0, $e);
        }

        $this->checkAudience($claims);

        if (!isset($claims['sub']) || !is_string($claims['sub']) || '' === $claims['sub']) {
            throw new OidcConfigurationException('The ID token carries no usable "sub" claim.');
        }
    }

    /**
     * Audience validation per OIDC Core 3.1.3.7.
     *
     * The token must name this client. Additional audiences are permitted —
     * providers legitimately add them, Keycloak's audience mappers being the
     * common case — but then "azp" has to name us as the party the token was
     * issued to, which is what keeps a token minted for somebody else from
     * counting as ours.
     *
     * Done here rather than with the generic JOSE AudienceChecker, which
     * implements the JWT containment rule (RFC 7519 4.1.3) and knows nothing
     * about azp.
     *
     * @param array<string, mixed> $claims
     */
    private function checkAudience(array $claims): void
    {
        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? array_values($audience) : [$audience];
        $audiences = array_values(array_filter($audiences, 'is_string'));

        if ([] === $audiences) {
            throw new OidcConfigurationException('The token carries no usable audience.');
        }

        $addressesUs = false;
        foreach ($audiences as $value) {
            if (hash_equals($this->config->getClientId(), $value)) {
                $addressesUs = true;
                break;
            }
        }

        if (!$addressesUs) {
            throw new OidcConfigurationException('The token does not list this client as an audience.');
        }

        if (count($audiences) < 2) {
            return;
        }

        // Several audiences: only azp still distinguishes "issued to us" from
        // "issued to someone else and we are merely listed".
        $azp = $claims['azp'] ?? null;
        if (!is_string($azp) || !hash_equals($this->config->getClientId(), $azp)) {
            throw new OidcConfigurationException('The token has multiple audiences but "azp" does not name this client.');
        }
    }

    /**
     * Binding the token to the nonce we generated is what stops a token obtained
     * in one login attempt from being replayed into another.
     *
     * @param array<string, mixed> $claims
     */
    private function checkNonce(array $claims, string $expectedNonce): void
    {
        $nonce = $claims['nonce'] ?? null;
        if (!is_string($nonce) || !hash_equals($expectedNonce, $nonce)) {
            throw new OidcConfigurationException('The ID token nonce does not match the login attempt.');
        }
    }

    /**
     * "azp" is optional, but when a provider sends it, it names the party the
     * token was issued to — and that has to be us.
     *
     * @param array<string, mixed> $claims
     */
    private function checkAuthorizedParty(array $claims): void
    {
        $azp = $claims['azp'] ?? null;
        if (null !== $azp && (!is_string($azp) || !hash_equals($this->config->getClientId(), $azp))) {
            throw new OidcConfigurationException('The ID token was issued for a different client.');
        }
    }

    /**
     * @return array<string, Algorithm>
     */
    private static function supportedAlgorithms(): array
    {
        return [
            'RS256' => new RS256(),
            'RS384' => new RS384(),
            'RS512' => new RS512(),
            'PS256' => new PS256(),
            'PS384' => new PS384(),
            'PS512' => new PS512(),
            'ES256' => new ES256(),
            'ES384' => new ES384(),
            'ES512' => new ES512(),
        ];
    }
}
