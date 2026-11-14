<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\CSRFProtectionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class CSRFProtectionServiceTest extends TestCase
{
    #[DataProvider('tokenPairs')]
    public function testOnlyMatchingNonEmptyStringTokensAreAccepted(mixed $saved, mixed $submitted, bool $valid): void
    {
        $session = new Session(new MockArraySessionStorage());
        if (null !== $saved) {
            $session->set('_csrf_token', $saved);
        }
        $request = new Request([], null === $submitted ? [] : ['_csrf_token' => $submitted]);
        $service = $this->service($session, $request);

        self::assertSame($valid, $service->validateCSRFToken($request));
        self::assertSame($saved, $session->get('_csrf_token'), 'Validation without invalidation must preserve the token.');
    }

    /** @return iterable<string, array{mixed, mixed, bool}> */
    public static function tokenPairs(): iterable
    {
        $token = 'LegacyCsrfToken123456';
        yield 'matching strings' => [$token, $token, true];
        yield 'both missing' => [null, null, false];
        yield 'session token missing' => [null, $token, false];
        yield 'submitted token missing' => [$token, null, false];
        yield 'both empty' => ['', '', false];
        yield 'empty session token' => ['', $token, false];
        yield 'empty submitted token' => [$token, '', false];
        yield 'wrong token' => [$token, 'WrongCsrfToken1234567', false];
        yield 'different case' => [$token, strtolower($token), false];
        yield 'leading whitespace' => [$token, ' '.$token, false];
        yield 'submitted boolean' => [$token, true, false];
        yield 'stored boolean' => [true, $token, false];
        yield 'both boolean' => [true, true, false];
        yield 'both false' => [false, false, false];
        yield 'stored integer' => [123, '123', false];
        yield 'submitted integer' => ['123', 123, false];
        yield 'stored array' => [[$token], $token, false];
        yield 'numeric-looking strings' => ['0e'.str_repeat('1', 18), '0e'.str_repeat('2', 18), false];
        yield 'matching numeric-looking string' => ['0e'.str_repeat('1', 18), '0e'.str_repeat('1', 18), true];
    }

    public function testArrayTokenIsRejectedByTheRequestBag(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('_csrf_token', 'LegacyCsrfToken123456');
        $request = new Request([], ['_csrf_token' => ['unexpected']]);
        $service = $this->service($session, $request);
        $this->expectException(BadRequestException::class);

        $service->validateCSRFToken($request);
    }

    public function testGeneratedTokenKeepsTheLegacyFormatAndWorksForRepeatedSubmits(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $service = $this->service($session, $request);

        $token = $service->getCSRFTokenForForm();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{20}$/D', $token);
        self::assertSame($token, $session->get('_csrf_token'));
        $request->request->set('_csrf_token', $token);
        self::assertTrue($service->validateCSRFToken($request));
        self::assertTrue($service->validateCSRFToken($request));
        self::assertSame($token, $session->get('_csrf_token'));
    }

    public function testCustomFieldNameUsesTheSameSessionToken(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $service = $this->service($session, $request);
        $token = $service->getCSRFTokenForForm();
        $request->request->set('custom_token', $token);
        $request->request->set('_csrf_token', 'wrong-token');

        self::assertTrue($service->validateCSRFToken($request, false, 'custom_token'));
        self::assertFalse($service->validateCSRFToken($request));
        self::assertSame($token, $session->get('_csrf_token'));
    }

    #[DataProvider('validationResults')]
    public function testInvalidationReplacesTheTokenAfterBothAcceptedAndRejectedAttempts(bool $valid): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $service = $this->service($session, $request);
        $original = $service->getCSRFTokenForForm();
        $request->request->set('_csrf_token', $valid ? $original : 'wrong-token');

        self::assertSame($valid, $service->validateCSRFToken($request, true));

        $replacement = $session->get('_csrf_token');
        self::assertIsString($replacement);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{20}$/D', $replacement);
        self::assertNotSame($original, $replacement);
        $request->request->set('_csrf_token', $original);
        self::assertFalse($service->validateCSRFToken($request));
        $request->request->set('_csrf_token', $replacement);
        self::assertTrue($service->validateCSRFToken($request));
    }

    /** @return iterable<string, array{bool}> */
    public static function validationResults(): iterable
    {
        yield 'accepted attempt' => [true];
        yield 'rejected attempt' => [false];
    }

    private function service(Session $session, Request $request): CSRFProtectionService
    {
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);

        return new CSRFProtectionService($stack);
    }
}
