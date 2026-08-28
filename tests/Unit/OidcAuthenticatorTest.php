<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Security\OidcAuthenticator;
use App\Service\Oidc\OidcClient;
use App\Service\Oidc\OidcConfiguration;
use App\Service\Oidc\OidcIdTokenValidator;
use App\Service\Oidc\OidcDiscoveryService;
use App\Service\Oidc\OidcUserResolver;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

final class OidcAuthenticatorTest extends TestCase
{
    private static function authenticator(bool $enabled = true, int $rateLimit = 20): OidcAuthenticator
    {
        $config = new OidcConfiguration(
            $enabled, 'https://id.example.com', 'fewohbee', 's3cr3t', 'openid email', '',
            'email', true, false, false,
        );

        $limiter = new RateLimiterFactory(
            ['id' => 'oidc_callback', 'policy' => 'fixed_window', 'limit' => $rateLimit, 'interval' => '5 minutes'],
            new InMemoryStorage(),
        );

        // The collaborators below are final, so they are built for real. None
        // of them is reached by these tests: every case fails on the state,
        // error or code check, which all run before the token exchange.
        $discovery = new OidcDiscoveryService($config, new MockHttpClient([]), new ArrayAdapter());
        $validator = new OidcIdTokenValidator($config, $discovery, new MockClock());

        return new OidcAuthenticator(
            $config,
            new OidcClient($config, $discovery, new MockHttpClient([]), $validator),
            $validator,
            new OidcUserResolver($config, self::createStub(UserRepository::class), self::createStub(EntityManagerInterface::class), new NullLogger()),
            self::createStub(UrlGeneratorInterface::class),
            $limiter,
            new NullLogger(),
        );
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $session
     */
    private static function callbackRequest(array $query = [], array $session = []): Request
    {
        $request = Request::create('/login/oidc/callback', 'GET', $query);
        $sessionObject = new Session(new MockArraySessionStorage());
        foreach ($session as $key => $value) {
            $sessionObject->set($key, $value);
        }
        $request->setSession($sessionObject);

        return $request;
    }

    public function testSupportsTheCallbackRoute(): void
    {
        self::assertTrue(self::authenticator()->supports(Request::create('/login/oidc/callback', 'GET')));
    }

    public function testDoesNotSupportTheCallbackWhenSingleSignOnIsDisabled(): void
    {
        self::assertFalse(self::authenticator(enabled: false)->supports(Request::create('/login/oidc/callback', 'GET')));
    }

    public function testDoesNotSupportOtherMethodsOrPaths(): void
    {
        $authenticator = self::authenticator();

        self::assertFalse($authenticator->supports(Request::create('/login/oidc/callback', 'POST')));
        self::assertFalse($authenticator->supports(Request::create('/login/oidc/start', 'GET')));
        self::assertFalse($authenticator->supports(Request::create('/login', 'GET')));
    }

    public function testRejectsACallbackWithoutAStateInTheSession(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('login.oidc.error.state');

        self::authenticator()->authenticate(self::callbackRequest(['code' => 'abc', 'state' => 'whatever']));
    }

    public function testRejectsAMismatchedState(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('login.oidc.error.state');

        self::authenticator()->authenticate(self::callbackRequest(
            ['code' => 'abc', 'state' => 'forged'],
            [OidcAuthenticator::SESSION_STATE => 'expected'],
        ));
    }

    /**
     * A callback whose state does not match is not ours, so it must leave the
     * pending login untouched. Otherwise anyone able to make the browser hit
     * the callback URL could cancel a login in progress.
     */
    public function testAForgedCallbackDoesNotCancelAPendingLogin(): void
    {
        $request = self::callbackRequest(
            ['code' => 'abc', 'state' => 'forged'],
            [OidcAuthenticator::SESSION_STATE => 'expected', OidcAuthenticator::SESSION_NONCE => 'n'],
        );

        try {
            self::authenticator()->authenticate($request);
            self::fail('Expected the authentication to fail.');
        } catch (CustomUserMessageAuthenticationException) {
            // expected
        }

        self::assertSame('expected', $request->getSession()->get(OidcAuthenticator::SESSION_STATE));
        self::assertSame('n', $request->getSession()->get(OidcAuthenticator::SESSION_NONCE));
    }

    /**
     * Once the state matches, the callback is ours and its one-time values are
     * spent whatever happens next — a second use must not be possible.
     */
    public function testAMatchingStateIsConsumedEvenWhenTheAttemptFails(): void
    {
        $request = self::callbackRequest(
            ['state' => 'expected'],
            [OidcAuthenticator::SESSION_STATE => 'expected', OidcAuthenticator::SESSION_NONCE => 'n'],
        );

        try {
            self::authenticator()->authenticate($request);
            self::fail('Expected the authentication to fail.');
        } catch (CustomUserMessageAuthenticationException) {
            // missing code
        }

        self::assertFalse($request->getSession()->has(OidcAuthenticator::SESSION_STATE));
        self::assertFalse($request->getSession()->has(OidcAuthenticator::SESSION_NONCE));
    }

    /**
     * Providers echo state on error responses too (RFC 6749 4.1.2.1), so a
     * cancellation is only honoured when it carries our state.
     */
    public function testReportsAProviderSideError(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('login.oidc.error.provider');

        self::authenticator()->authenticate(self::callbackRequest(
            ['error' => 'access_denied', 'state' => 'expected'],
            [OidcAuthenticator::SESSION_STATE => 'expected'],
        ));
    }

    public function testAnErrorCallbackWithoutOurStateIsIgnored(): void
    {
        $request = self::callbackRequest(
            ['error' => 'access_denied'],
            [OidcAuthenticator::SESSION_STATE => 'expected'],
        );

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('login.oidc.error.state');

        self::authenticator()->authenticate($request);
    }

    public function testRejectsACallbackWithoutACode(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('login.oidc.error.token');

        self::authenticator()->authenticate(self::callbackRequest(
            ['state' => 'expected'],
            [OidcAuthenticator::SESSION_STATE => 'expected'],
        ));
    }

    /**
     * The limiter guards the outbound token request, so it is only consumed by
     * callbacks that got past the state check. A forged callback must not be
     * able to burn the budget of everyone behind the same NAT address.
     */
    public function testTheCallbackIsRateLimitedOnceTheStateMatches(): void
    {
        $authenticator = self::authenticator(rateLimit: 1);
        $session = [OidcAuthenticator::SESSION_STATE => 'expected'];

        try {
            $authenticator->authenticate(self::callbackRequest(['state' => 'expected'], $session));
        } catch (CustomUserMessageAuthenticationException) {
            // first attempt consumes the single token
        }

        $this->expectException(TooManyLoginAttemptsAuthenticationException::class);
        $authenticator->authenticate(self::callbackRequest(['state' => 'expected'], $session));
    }

    public function testForgedCallbacksDoNotConsumeTheRateLimit(): void
    {
        $authenticator = self::authenticator(rateLimit: 1);

        foreach (range(1, 5) as $ignored) {
            try {
                $authenticator->authenticate(self::callbackRequest(['state' => 'forged']));
                self::fail('Expected the authentication to fail.');
            } catch (CustomUserMessageAuthenticationException $e) {
                self::assertSame('login.oidc.error.state', $e->getMessage());
            }
        }

        // The budget is still intact for the legitimate attempt.
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('login.oidc.error.token');
        $authenticator->authenticate(self::callbackRequest(
            ['state' => 'expected'],
            [OidcAuthenticator::SESSION_STATE => 'expected'],
        ));
    }
}
