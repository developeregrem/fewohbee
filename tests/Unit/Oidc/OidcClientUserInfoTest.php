<?php

declare(strict_types=1);

namespace App\Tests\Unit\Oidc;

use App\Exception\OidcConfigurationException;
use App\Service\Oidc\OidcClient;
use App\Service\Oidc\OidcConfiguration;
use App\Service\Oidc\OidcDiscoveryService;
use App\Service\Oidc\OidcIdTokenValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Covers reading claims from the UserInfo endpoint, which is how providers that
 * keep the ID token minimal (Authelia, Okta) deliver the e-mail address.
 */
final class OidcClientUserInfoTest extends TestCase
{
    private const ISSUER = 'https://id.example.com';
    private const SUBJECT = 'provider-subject-42';

    private static function config(): OidcConfiguration
    {
        return new OidcConfiguration(
            true, self::ISSUER, 'fewohbee', 's3cr3t', 'openid email', '',
            'email', true, false, false,
        );
    }

    /**
     * @param array<string, mixed>|null $discoveryOverrides
     */
    private static function client(MockResponse $userInfoResponse, ?array $discoveryOverrides = null): OidcClient
    {
        $document = array_merge([
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/auth',
            'token_endpoint' => self::ISSUER.'/token',
            'jwks_uri' => self::ISSUER.'/jwks',
            'userinfo_endpoint' => self::ISSUER.'/userinfo',
        ], $discoveryOverrides ?? []);

        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($document, \JSON_THROW_ON_ERROR)),
            $userInfoResponse,
        ]);

        $config = self::config();
        $discovery = new OidcDiscoveryService($config, $httpClient, new ArrayAdapter());

        return new OidcClient($config, $discovery, $httpClient, new OidcIdTokenValidator($config, $discovery, new MockClock()));
    }

    public function testReadsClaimsFromAJsonResponse(): void
    {
        $response = new MockResponse(
            json_encode(['sub' => self::SUBJECT, 'email' => 'staff@example.com', 'email_verified' => true], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );

        $claims = self::client($response)->fetchUserInfo('an-access-token', self::SUBJECT);

        self::assertSame('staff@example.com', $claims['email']);
        self::assertTrue($claims['email_verified']);
    }

    /**
     * OIDC Core 5.3.2: a response about a different subject must never be
     * accepted, or one user's UserInfo could be attached to another's login.
     */
    public function testRefusesAResponseAboutADifferentSubject(): void
    {
        $response = new MockResponse(
            json_encode(['sub' => 'somebody-else', 'email' => 'victim@example.com'], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );

        $this->expectException(OidcConfigurationException::class);
        $this->expectExceptionMessageMatches('/different subject/');

        self::client($response)->fetchUserInfo('an-access-token', self::SUBJECT);
    }

    public function testRefusesAResponseWithoutASubject(): void
    {
        $response = new MockResponse(
            json_encode(['email' => 'staff@example.com'], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );

        $this->expectException(OidcConfigurationException::class);

        self::client($response)->fetchUserInfo('an-access-token', self::SUBJECT);
    }

    public function testFailsWhenTheProviderPublishesNoUserInfoEndpoint(): void
    {
        $response = new MockResponse('{}');

        $this->expectException(OidcConfigurationException::class);
        $this->expectExceptionMessageMatches('/no UserInfo endpoint/');

        self::client($response, ['userinfo_endpoint' => null])->fetchUserInfo('an-access-token', self::SUBJECT);
    }

    public function testFailsOnANonJsonResponse(): void
    {
        $response = new MockResponse('<html>not json</html>', ['response_headers' => ['content-type' => 'text/html']]);

        $this->expectException(OidcConfigurationException::class);

        self::client($response)->fetchUserInfo('an-access-token', self::SUBJECT);
    }

    public function testSendsTheAccessTokenAsBearer(): void
    {
        $seen = null;
        $document = json_encode([
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/auth',
            'token_endpoint' => self::ISSUER.'/token',
            'jwks_uri' => self::ISSUER.'/jwks',
            'userinfo_endpoint' => self::ISSUER.'/userinfo',
        ], \JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($document, &$seen): MockResponse {
            if (str_ends_with($url, '/userinfo')) {
                $seen = $options['normalized_headers']['authorization'][0] ?? null;

                return new MockResponse(
                    json_encode(['sub' => self::SUBJECT, 'email' => 'staff@example.com'], \JSON_THROW_ON_ERROR),
                    ['response_headers' => ['content-type' => 'application/json']],
                );
            }

            return new MockResponse($document);
        });

        $config = self::config();
        $discovery = new OidcDiscoveryService($config, $httpClient, new ArrayAdapter());
        $client = new OidcClient($config, $discovery, $httpClient, new OidcIdTokenValidator($config, $discovery, new MockClock()));

        $client->fetchUserInfo('an-access-token', self::SUBJECT);

        self::assertSame('Authorization: Bearer an-access-token', $seen);
    }
}
