<?php

declare(strict_types=1);

namespace App\Tests\Unit\Oidc;

use App\Exception\OidcConfigurationException;
use App\Service\Oidc\OidcConfiguration;
use App\Service\Oidc\OidcUserMatching;
use PHPUnit\Framework\TestCase;

final class OidcConfigurationTest extends TestCase
{
    private static function config(
        bool $enabled = true,
        string $issuer = 'https://id.example.com/',
        string $clientId = 'fewohbee',
        string $clientSecret = 's3cr3t',
        string $scopes = 'openid profile email',
        string $userMatching = 'email',
        bool $requireVerifiedEmail = true,
        bool $enforce = false,
        bool $endSession = false,
        bool $requireHttps = true,
    ): OidcConfiguration {
        return new OidcConfiguration(
            $enabled, $issuer, $clientId, $clientSecret, $scopes, '',
            $userMatching, $requireVerifiedEmail, $enforce, $endSession, $requireHttps,
        );
    }

    public function testIsNotEnabledWithoutIssuerOrClientId(): void
    {
        self::assertFalse(self::config(issuer: '')->isEnabled());
        self::assertFalse(self::config(clientId: '  ')->isEnabled());
        self::assertFalse(self::config(enabled: false)->isEnabled());
        self::assertTrue(self::config()->isEnabled());
    }

    /**
     * A stray OIDC_ENFORCE without a working provider must not lock everyone
     * out of the installation.
     */
    public function testEnforceRequiresAWorkingConfiguration(): void
    {
        self::assertFalse(self::config(enabled: false, enforce: true)->isEnforced());
        self::assertFalse(self::config(issuer: '', enforce: true)->isEnforced());
        self::assertTrue(self::config(enforce: true)->isEnforced());
    }

    public function testEndSessionAlsoRequiresAWorkingConfiguration(): void
    {
        self::assertFalse(self::config(enabled: false, endSession: true)->isEndSessionEnabled());
        self::assertTrue(self::config(endSession: true)->isEndSessionEnabled());
    }

    public function testIssuerLosesTrailingSlash(): void
    {
        self::assertSame('https://id.example.com', self::config()->getIssuer());
    }

    public function testOpenidScopeIsAlwaysRequested(): void
    {
        self::assertSame(['openid', 'profile'], self::config(scopes: 'profile')->getScopes());
        self::assertSame(['openid', 'profile', 'email'], self::config()->getScopes());
    }

    public function testUnknownMatchingModeFallsBackToEmail(): void
    {
        self::assertSame(OidcUserMatching::Username, self::config(userMatching: 'username')->getUserMatching());
        self::assertSame(OidcUserMatching::Email, self::config(userMatching: 'e-mail')->getUserMatching());
        self::assertSame(OidcUserMatching::Email, self::config(userMatching: '')->getUserMatching());
    }

    public function testAssertConfiguredRejectsIncompleteSetup(): void
    {
        $this->expectException(OidcConfigurationException::class);
        self::config(clientSecret: '')->assertConfigured();
    }

    public function testAssertConfiguredRejectsRelativeIssuer(): void
    {
        $this->expectException(OidcConfigurationException::class);
        self::config(issuer: 'id.example.com')->assertConfigured();
    }

    /**
     * Plain HTTP would expose the client secret and every token in transit.
     * The parameter is true in prod and relaxed for dev and test, so a local
     * provider without a certificate stays usable.
     */
    public function testAssertConfiguredRejectsPlainHttpWhenHttpsIsRequired(): void
    {
        $this->expectException(OidcConfigurationException::class);
        $this->expectExceptionMessageMatches('/HTTPS/');
        self::config(issuer: 'http://id.example.com')->assertConfigured();
    }

    public function testAssertConfiguredAllowsPlainHttpWhenHttpsIsNotRequired(): void
    {
        self::config(issuer: 'http://localhost:9091', requireHttps: false)->assertConfigured();
        $this->expectNotToPerformAssertions();
    }

    public function testAssertConfiguredAcceptsCompleteSetup(): void
    {
        self::config()->assertConfigured();
        $this->expectNotToPerformAssertions();
    }
}
