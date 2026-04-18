<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\UserRepository;
use App\Repository\WebauthnCredentialRepository;
use App\Security\WebauthnAuthenticator;
use App\Service\WebauthnService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class WebauthnAuthenticatorTest extends TestCase
{
    private WebauthnAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->authenticator = new WebauthnAuthenticator(
            $this->createStub(WebauthnService::class),
            $this->createStub(WebauthnCredentialRepository::class),
            $this->createStub(UserRepository::class),
        );
    }

    public function testSupportsPostToLoginWebauthn(): void
    {
        $request = Request::create('/login/webauthn', 'POST');

        self::assertTrue($this->authenticator->supports($request));
    }

    public function testDoesNotSupportGetToLoginWebauthn(): void
    {
        $request = Request::create('/login/webauthn', 'GET');

        self::assertFalse($this->authenticator->supports($request));
    }

    public function testDoesNotSupportPostToOtherPaths(): void
    {
        $request = Request::create('/login', 'POST');
        self::assertFalse($this->authenticator->supports($request));

        $request = Request::create('/profile/security/devices/add', 'POST');
        self::assertFalse($this->authenticator->supports($request));
    }

    public function testAuthenticateThrowsWithoutChallenge(): void
    {
        $request = Request::create('/login/webauthn', 'POST', content: '{}');
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
        ));

        $this->expectException(\Symfony\Component\Security\Core\Exception\AuthenticationException::class);
        $this->expectExceptionMessage('No authentication challenge in progress.');

        $this->authenticator->authenticate($request);
    }

    public function testOnAuthenticationSuccessReturnsJson(): void
    {
        $request = Request::create('/login/webauthn', 'POST');
        $token = $this->createStub(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class);

        $response = $this->authenticator->onAuthenticationSuccess($request, $token, 'main');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"success":true}', $response->getContent());
    }

    public function testOnAuthenticationFailureReturnsUnauthorized(): void
    {
        $request = Request::create('/login/webauthn', 'POST');
        $exception = new \Symfony\Component\Security\Core\Exception\AuthenticationException('Test error');

        $response = $this->authenticator->onAuthenticationFailure($request, $exception);

        self::assertSame(401, $response->getStatusCode());
    }
}
