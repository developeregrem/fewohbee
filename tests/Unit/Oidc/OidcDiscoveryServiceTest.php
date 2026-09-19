<?php

declare(strict_types=1);

namespace App\Tests\Unit\Oidc;

use App\Exception\OidcConfigurationException;
use App\Service\Oidc\OidcClientAuthMethod;
use App\Service\Oidc\OidcConfiguration;
use App\Service\Oidc\OidcDiscoveryService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OidcDiscoveryServiceTest extends TestCase
{
    private const ISSUER = 'https://id.example.com';

    /**
     * @param array<string, mixed>|null $document null serves a valid default document
     */
    private static function service(?array $document = null, ?MockHttpClient $client = null, bool $requireHttps = true): OidcDiscoveryService
    {
        $config = new OidcConfiguration(
            true, self::ISSUER, 'fewohbee', 's3cr3t', 'openid email', '',
            'email', true, false, false, $requireHttps,
        );

        $client ??= new MockHttpClient(new MockResponse(json_encode($document ?? self::validDocument())));

        return new OidcDiscoveryService($config, $client, new ArrayAdapter());
    }

    /**
     * @return array<string, mixed>
     */
    private static function validDocument(): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/auth',
            'token_endpoint' => self::ISSUER.'/token',
            'jwks_uri' => self::ISSUER.'/jwks',
            'end_session_endpoint' => self::ISSUER.'/logout',
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
        ];
    }

    public function testReadsTheDiscoveryDocument(): void
    {
        $metadata = self::service()->getMetadata();

        self::assertSame(self::ISSUER.'/auth', $metadata->authorizationEndpoint);
        self::assertSame(self::ISSUER.'/token', $metadata->tokenEndpoint);
        self::assertSame(self::ISSUER.'/jwks', $metadata->jwksUri);
        self::assertSame(self::ISSUER.'/logout', $metadata->endSessionEndpoint);
    }

    /**
     * Mix-up defence: a document served under our issuer URL that names a
     * different issuer means we are talking to the wrong party.
     */
    public function testRejectsADocumentAnnouncingADifferentIssuer(): void
    {
        $document = self::validDocument();
        $document['issuer'] = 'https://evil.example.com';

        $this->expectException(OidcConfigurationException::class);
        $this->expectExceptionMessageMatches('/announces issuer/');

        self::service($document)->getMetadata();
    }

    public function testRejectsADocumentWithoutATokenEndpoint(): void
    {
        $document = self::validDocument();
        unset($document['token_endpoint']);

        $this->expectException(OidcConfigurationException::class);

        self::service($document)->getMetadata();
    }

    public function testRejectsAnUnreachableProvider(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 500]));

        $this->expectException(OidcConfigurationException::class);

        self::service(client: $client)->getMetadata();
    }

    public function testTheDocumentIsFetchedOnlyOnce(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(self::validDocument())),
            new MockResponse('{"issuer": "https://evil.example.com"}'),
        ]);
        $service = self::service(client: $client);

        $service->getMetadata();
        $service->getMetadata();

        // A second request would have consumed the poisoned response above.
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testPrefersBasicClientAuthentication(): void
    {
        self::assertSame(OidcClientAuthMethod::Basic, self::service()->getMetadata()->resolveClientAuthMethod());
    }

    public function testFallsBackToPostWhenBasicIsNotOffered(): void
    {
        $document = self::validDocument();
        $document['token_endpoint_auth_methods_supported'] = ['client_secret_post'];

        self::assertSame(OidcClientAuthMethod::Post, self::service($document)->getMetadata()->resolveClientAuthMethod());
    }

    public function testAssumesBasicWhenTheProviderAnnouncesNothing(): void
    {
        $document = self::validDocument();
        unset($document['token_endpoint_auth_methods_supported']);

        self::assertSame(OidcClientAuthMethod::Basic, self::service($document)->getMetadata()->resolveClientAuthMethod());
    }

    /**
     * Sending a Basic header to a provider that only accepts private_key_jwt
     * produces an opaque error at the token endpoint; failing here names the
     * actual problem.
     */
    public function testFailsWhenNoSupportedClientAuthenticationIsOffered(): void
    {
        $document = self::validDocument();
        $document['token_endpoint_auth_methods_supported'] = ['private_key_jwt', 'none'];

        $this->expectException(OidcConfigurationException::class);
        $this->expectExceptionMessageMatches('/none of the supported client authentication methods/');

        self::service($document)->getMetadata()->resolveClientAuthMethod();
    }

    /**
     * The issuer is stored exactly as published, because token validation
     * compares it by exact string.
     */
    public function testTheIssuerIsKeptVerbatim(): void
    {
        $document = self::validDocument();
        $document['issuer'] = self::ISSUER.'/';

        self::assertSame(self::ISSUER.'/', self::service($document)->getMetadata()->issuer);
    }

    /**
     * A conforming provider never advertises plain HTTP here. In practice it is
     * the signature of a provider behind a proxy publishing its internal
     * addresses — which would send the client secret in the clear and, worse,
     * make the JWKS swappable in transit.
     */
    public function testRejectsNonHttpsEndpointsFromTheDiscoveryDocument(): void
    {
        foreach (['token_endpoint', 'jwks_uri', 'userinfo_endpoint', 'authorization_endpoint'] as $endpoint) {
            $document = self::validDocument();
            $document['userinfo_endpoint'] ??= self::ISSUER.'/userinfo';
            $document[$endpoint] = 'http://internal-host:8080/x';

            try {
                self::service($document)->getMetadata();
                self::fail(sprintf('A plain HTTP %s must be rejected.', $endpoint));
            } catch (OidcConfigurationException $e) {
                self::assertStringContainsString($endpoint, $e->getMessage());
            }
        }
    }

    public function testAllowsPlainHttpEndpointsWhenHttpsIsNotRequired(): void
    {
        $document = self::validDocument();
        $document['token_endpoint'] = 'http://localhost:9091/token';

        $metadata = self::service($document, requireHttps: false)->getMetadata();

        self::assertSame('http://localhost:9091/token', $metadata->tokenEndpoint);
    }

    public function testMissingEndSessionEndpointIsNull(): void
    {
        $document = self::validDocument();
        unset($document['end_session_endpoint']);

        self::assertNull(self::service($document)->getMetadata()->endSessionEndpoint);
    }
}
