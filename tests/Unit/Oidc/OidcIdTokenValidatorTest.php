<?php

declare(strict_types=1);

namespace App\Tests\Unit\Oidc;

use App\Exception\OidcConfigurationException;
use App\Service\Oidc\OidcConfiguration;
use App\Service\Oidc\OidcDiscoveryService;
use App\Service\Oidc\OidcIdTokenValidator;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OidcIdTokenValidatorTest extends TestCase
{
    private const ISSUER = 'https://id.example.com';
    private const CLIENT_ID = 'fewohbee';
    private const NONCE = 'the-expected-nonce';

    private const RS256_PUBLIC_JWK = '{"alg":"RS256","use":"sig","kid":"rsa-fixture","kty":"RSA","n":"i9rnwV76NI2DuC9QHrEB1fZby9ws5mcGv9P9Icj5jFMGIRbq4HWQS2Fq7YCCoLOcRzzo-6SeJ7cB6k4-O5rixqkcDZGOyrgWI6GD7t8GKS6Qn4F61sRQCBYxqJ1NBShhgyudy9P3LiJmXaqy0Qju9US8MN-XIpWTk_6ht757EwHVNKxWFlriiVbB3-b9yIq2BCugbPfYPTDoxOm4kcnKNniZPeOv-H7h5sILJnPCBayj9V1mt1JglZI6xK6EbovNXr_R_jIcxpGnOz9e7uQtqXLEAsF5h-i-WKyKSFD5vJESCK_cRmkZ3UUiEpfdAieor_rcxW0iusnhThvf1nJQ0Q","e":"AQAB"}';

    private const RS256_TOKEN = 'eyJhbGciOiJSUzI1NiIsImtpZCI6InJzYS1maXh0dXJlIn0.eyJpc3MiOiJodHRwczpcL1wvaWQuZXhhbXBsZS5jb20iLCJzdWIiOiJwcm92aWRlci1zdWJqZWN0LTQyIiwiYXVkIjoiZmV3b2hiZWUiLCJleHAiOjE3ODc5MTg3MDAsImlhdCI6MTc4NzkxODQwMCwibm9uY2UiOiJ0aGUtZXhwZWN0ZWQtbm9uY2UiLCJlbWFpbCI6InN0YWZmQGV4YW1wbGUuY29tIiwiZW1haWxfdmVyaWZpZWQiOnRydWV9.eBnOI7b2Udr0ZNkvKoAdcZXMtY44r2HAU2noI-l5YadBqGuzXGhkUUUmJVRHAwV_k889F5TjHYr2SPwujsy30qUrFp9wigvjgGqGHSKmmoCMCTS1HpEX-9CO6e3hT5naDw1nCDEnDQkg4sm8qqaYmbDNf-JP6TkBC5K7wBjxqEfpF1QdeTcwpyEUlZ7FGV2FwwAjmsIc94UbHcnylV8Si_AhT0IHLxGaD_YiGtq2uYy3FlCVX8JQ2uOWOxNwTow_m1XN35sqNACU1PJ9BLzxFijLlJ7CQpEQwJDHuCgLWDF6FxhqNDOmvUwXGQ9J7ECEg2A-KTDvLixYR7XULdV0LA';

    private static ?JWK $signingKey = null;

    private MockClock $clock;

    protected function setUp(): void
    {
        // Claim-validation tests use fast asymmetric EC signatures. RS256 is
        // covered separately with a stable pre-signed fixture below.
        self::$signingKey ??= JWKFactory::createECKey('P-256', ['alg' => 'ES256', 'use' => 'sig', 'kid' => 'key-1']);
        $this->clock = new MockClock('2026-08-28 12:00:00');
    }

    private static function config(): OidcConfiguration
    {
        return new OidcConfiguration(
            true, self::ISSUER, self::CLIENT_ID, 's3cr3t', 'openid email', '',
            'email', true, false, false,
        );
    }

    /**
     * Builds the real discovery service on a mocked transport, so the key
     * lookup and its caching are exercised rather than stubbed away.
     *
     * @param list<JWKSet>|null $keySets served to successive JWKS requests;
     *                                   defaults to a single set holding the signing key
     */
    private function validator(?array $keySets = null): OidcIdTokenValidator
    {
        $keySets ??= [new JWKSet([self::$signingKey->toPublic()])];

        $responses = [new MockResponse(json_encode([
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/auth',
            'token_endpoint' => self::ISSUER.'/token',
            'jwks_uri' => self::ISSUER.'/jwks',
        ], \JSON_THROW_ON_ERROR))];
        foreach ($keySets as $keySet) {
            $responses[] = new MockResponse(json_encode($keySet, \JSON_THROW_ON_ERROR));
        }

        $discovery = new OidcDiscoveryService(self::config(), new MockHttpClient($responses), new ArrayAdapter());

        return new OidcIdTokenValidator(self::config(), $discovery, $this->clock);
    }

    /**
     * @param array<string, mixed> $claimOverrides
     */
    private function token(array $claimOverrides = [], ?JWK $key = null, string $alg = 'ES256', ?string $kid = 'key-1'): string
    {
        $now = $this->clock->now()->getTimestamp();
        $claims = array_merge([
            'iss' => self::ISSUER,
            'sub' => 'provider-subject-42',
            'aud' => self::CLIENT_ID,
            'exp' => $now + 300,
            'iat' => $now,
            'nonce' => self::NONCE,
            'email' => 'staff@example.com',
            'email_verified' => true,
        ], $claimOverrides);

        foreach ($claims as $name => $value) {
            if (null === $value) {
                unset($claims[$name]);
            }
        }

        $header = ['alg' => $alg];
        if (null !== $kid) {
            $header['kid'] = $kid;
        }

        $builder = new JWSBuilder(new AlgorithmManager([new ES256(), new HS256()]));
        $jws = $builder->create()
            ->withPayload(json_encode($claims, \JSON_THROW_ON_ERROR))
            ->addSignature($key ?? self::$signingKey, $header)
            ->build();

        return (new CompactSerializer())->serialize($jws);
    }

    public function testAcceptsAValidToken(): void
    {
        $claims = $this->validator()->validate($this->token(), self::NONCE);

        self::assertSame('provider-subject-42', $claims['sub']);
        self::assertSame('staff@example.com', $claims['email']);
    }

    /** Verify RS256 support without calculating an expensive private RSA signature at runtime. */
    public function testAcceptsAPreSignedRs256Token(): void
    {
        $keySet = new JWKSet([JWK::createFromJson(self::RS256_PUBLIC_JWK)]);

        $claims = $this->validator([$keySet])->validate(self::RS256_TOKEN, self::NONCE);

        self::assertSame('provider-subject-42', $claims['sub']);
    }

    public function testRejectsAWrongIssuer(): void
    {
        $this->expectException(OidcConfigurationException::class);
        $this->validator()->validate($this->token(['iss' => 'https://evil.example.com']), self::NONCE);
    }

    public function testRejectsATokenIssuedForAnotherClient(): void
    {
        $this->expectException(OidcConfigurationException::class);
        $this->validator()->validate($this->token(['aud' => 'some-other-app']), self::NONCE);
    }

    public function testRejectsAnExpiredToken(): void
    {
        $now = $this->clock->now()->getTimestamp();

        $this->expectException(OidcConfigurationException::class);
        $this->validator()->validate($this->token(['exp' => $now - 3600]), self::NONCE);
    }

    /**
     * Replaying a token from another login attempt must not work.
     */
    public function testRejectsAMismatchedNonce(): void
    {
        $this->expectException(OidcConfigurationException::class);
        $this->expectExceptionMessageMatches('/nonce/');
        $this->validator()->validate($this->token(), 'a-different-nonce');
    }

    public function testRejectsAMissingNonce(): void
    {
        $this->expectException(OidcConfigurationException::class);
        $this->validator()->validate($this->token(['nonce' => null]), self::NONCE);
    }

    public function testAcceptsASingleElementAudienceArray(): void
    {
        $claims = $this->validator()->validate($this->token(['aud' => [self::CLIENT_ID]]), self::NONCE);

        self::assertSame('provider-subject-42', $claims['sub']);
    }

    /**
     * Providers legitimately add audiences (Keycloak's audience mappers being
     * the common case). They are accepted as long as azp names us as the party
     * the token was issued to.
     */
    public function testAcceptsAdditionalAudiencesWhenAzpNamesThisClient(): void
    {
        $claims = $this->validator()->validate(
            $this->token(['aud' => [self::CLIENT_ID, 'account'], 'azp' => self::CLIENT_ID]),
            self::NONCE,
        );

        self::assertSame('provider-subject-42', $claims['sub']);
    }

    /**
     * With several audiences, azp is the only thing separating "issued to us"
     * from "issued to someone else who merely listed us too".
     */
    public function testRejectsMultipleAudiencesWithoutAzp(): void
    {
        $this->expectException(OidcConfigurationException::class);
        $this->expectExceptionMessageMatches('/multiple audiences/');
        $this->validator()->validate($this->token(['aud' => [self::CLIENT_ID, 'account']]), self::NONCE);
    }

    public function testRejectsAnAudienceListWithoutThisClient(): void
    {
        $this->expectException(OidcConfigurationException::class);
        $this->expectExceptionMessageMatches('/does not list this client/');
        $this->validator()->validate(
            $this->token(['aud' => ['account', 'another-app'], 'azp' => 'another-app']),
            self::NONCE,
        );
    }

    /**
     * The issuer is matched by exact string, so a trailing slash the provider
     * does not publish must not be accepted.
     */
    public function testRejectsAnIssuerThatOnlyDiffersByATrailingSlash(): void
    {
        $this->expectException(OidcConfigurationException::class);
        $this->validator()->validate($this->token(['iss' => self::ISSUER.'/']), self::NONCE);
    }

    public function testRejectsAMismatchedAuthorizedParty(): void
    {
        $this->expectException(OidcConfigurationException::class);
        $this->expectExceptionMessageMatches('/different client/');
        $this->validator()->validate($this->token(['azp' => 'some-other-app']), self::NONCE);
    }

    /**
     * The classic JWT forgery: an attacker strips the signature and sets
     * alg to "none".
     */
    public function testRejectsTheNoneAlgorithm(): void
    {
        $claims = json_encode(['iss' => self::ISSUER, 'sub' => 'x', 'aud' => self::CLIENT_ID, 'nonce' => self::NONCE]);
        $encode = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $forged = $encode('{"alg":"none"}').'.'.$encode($claims).'.';

        $this->expectException(OidcConfigurationException::class);
        $this->expectExceptionMessageMatches('/Unsupported ID token signature algorithm/');
        $this->validator()->validate($forged, self::NONCE);
    }

    /**
     * HMAC would let anyone who can read the client secret mint tokens.
     */
    public function testRejectsSymmetricAlgorithms(): void
    {
        $hmacKey = JWKFactory::createFromSecret('s3cr3t-s3cr3t-s3cr3t-s3cr3t-s3cr3t', ['alg' => 'HS256']);

        $this->expectException(OidcConfigurationException::class);
        $this->expectExceptionMessageMatches('/Unsupported ID token signature algorithm/');
        $this->validator()->validate($this->token(key: $hmacKey, alg: 'HS256', kid: null), self::NONCE);
    }

    public function testRejectsASignatureFromAnUnknownKey(): void
    {
        $attackerKey = JWKFactory::createECKey('P-256', ['alg' => 'ES256', 'use' => 'sig', 'kid' => 'key-1']);

        $this->expectException(OidcConfigurationException::class);
        $this->expectExceptionMessageMatches('/signature could not be verified/');
        $this->validator()->validate($this->token(key: $attackerKey), self::NONCE);
    }

    /**
     * A signed UserInfo response must name us in aud and the provider in iss.
     * Without both, a signed blob the same provider issued for a different
     * client would be indistinguishable from one meant for this application.
     */
    public function testSignedUserInfoRequiresIssuerAndAudience(): void
    {
        $accepted = $this->validator()->validateSignedUserInfo(
            $this->token(['email' => 'staff@example.com', 'nonce' => null]),
            'provider-subject-42',
        );
        self::assertSame('staff@example.com', $accepted['email']);

        foreach (['iss', 'aud'] as $claim) {
            try {
                $this->validator()->validateSignedUserInfo(
                    $this->token([$claim => null, 'nonce' => null]),
                    'provider-subject-42',
                );
                self::fail(sprintf('A signed UserInfo response without "%s" must be rejected.', $claim));
            } catch (OidcConfigurationException) {
                // expected
            }
        }
    }

    public function testSignedUserInfoAboutAnotherSubjectIsRejected(): void
    {
        $this->expectException(OidcConfigurationException::class);
        $this->expectExceptionMessageMatches('/different subject/');

        $this->validator()->validateSignedUserInfo(
            $this->token(['nonce' => null]),
            'somebody-else',
        );
    }

    public function testRejectsAMalformedToken(): void
    {
        $this->expectException(OidcConfigurationException::class);
        $this->validator()->validate('not-a-jwt', self::NONCE);
    }

    /**
     * An unknown key id is what a provider key rotation looks like, so the key
     * set is refetched once before the token is rejected.
     */
    public function testRefetchesTheKeySetForAnUnknownKeyId(): void
    {
        $stale = new JWKSet([JWKFactory::createECKey('P-256', ['alg' => 'ES256', 'use' => 'sig', 'kid' => 'old-key'])->toPublic()]);
        $rotated = new JWKSet([self::$signingKey->toPublic()]);

        $claims = $this->validator([$stale, $rotated])->validate($this->token(), self::NONCE);

        self::assertSame('provider-subject-42', $claims['sub']);
    }
}
