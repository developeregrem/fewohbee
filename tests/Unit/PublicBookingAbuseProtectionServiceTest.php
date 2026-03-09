<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\PublicBookingException;
use App\Service\PublicBookingAbuseProtectionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class PublicBookingAbuseProtectionServiceTest extends TestCase
{
    /** Ensure availability requests are rejected when the configured limiter denies consumption. */
    public function testValidateAvailabilityRequestThrowsWhenRateLimitIsExceeded(): void
    {
        $request = new Request([], [
            'website' => '',
            'form_started_at' => (string) (time() - 5),
        ]);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->headers->set('User-Agent', 'PHPUnit');
        $request->headers->set('Accept-Language', 'de');

        $availabilityLimiter = $this->createLimiterFactoryReturningAccepted(false);
        $submitLimiter = $this->createLimiterFactoryReturningAccepted(true);

        $service = new PublicBookingAbuseProtectionService(
            $availabilityLimiter,
            $submitLimiter,
            $this->createRequestStackWithSession()
        );

        $this->expectException(PublicBookingException::class);
        $this->expectExceptionMessage('online_booking.error.try_again_later');
        $service->validateAvailabilityRequest($request);
    }

    /** Ensure submit requests are rejected when the submit limiter denies consumption. */
    public function testValidateSubmitRequestThrowsWhenSubmitRateLimitIsExceeded(): void
    {
        $requestStack = $this->createRequestStackWithSession();
        $session = $requestStack->getSession();
        self::assertNotNull($session);
        $session->set('public_booking_submit_token', 'valid-token');

        $request = new Request([], [
            'website' => '',
            'form_started_at' => (string) (time() - 5),
            'submit_token' => 'valid-token',
        ]);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->headers->set('User-Agent', 'PHPUnit');
        $request->headers->set('Accept-Language', 'de');

        $availabilityLimiter = $this->createLimiterFactoryReturningAccepted(true);
        $submitLimiter = $this->createLimiterFactoryReturningAccepted(false);

        $service = new PublicBookingAbuseProtectionService(
            $availabilityLimiter,
            $submitLimiter,
            $requestStack
        );

        $this->expectException(PublicBookingException::class);
        $this->expectExceptionMessage('online_booking.error.try_again_later_submit');
        $service->validateSubmitRequest($request);
    }

    /** Ensure a valid availability request passes all checks without throwing. */
    public function testValidateAvailabilityRequestPassesForValidRequest(): void
    {
        $request = new Request([], [
            'website' => '',
            'form_started_at' => (string) (time() - 5),
        ]);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->headers->set('User-Agent', 'PHPUnit');
        $request->headers->set('Accept-Language', 'de');

        $service = new PublicBookingAbuseProtectionService(
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createRequestStackWithSession()
        );

        $service->validateAvailabilityRequest($request);
        $this->addToAssertionCount(1);
    }

    /** Ensure a valid submit request with correct token passes all checks. */
    public function testValidateSubmitRequestPassesForValidRequest(): void
    {
        $requestStack = $this->createRequestStackWithSession();
        $session = $requestStack->getSession();
        $session->set('public_booking_submit_token', 'my-token');

        $request = new Request([], [
            'website' => '',
            'form_started_at' => (string) (time() - 5),
            'submit_token' => 'my-token',
        ]);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->headers->set('User-Agent', 'PHPUnit');
        $request->headers->set('Accept-Language', 'de');

        $service = new PublicBookingAbuseProtectionService(
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createLimiterFactoryReturningAccepted(true),
            $requestStack
        );

        $service->validateSubmitRequest($request);
        $this->addToAssertionCount(1);
    }

    /** Ensure requests with a filled honeypot field are rejected. */
    public function testHoneypotFilledRejectsRequest(): void
    {
        $request = new Request([], [
            'website' => 'http://spam.example.com',
            'form_started_at' => (string) (time() - 5),
        ]);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->headers->set('User-Agent', 'PHPUnit');
        $request->headers->set('Accept-Language', 'de');

        $service = new PublicBookingAbuseProtectionService(
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createRequestStackWithSession()
        );

        $this->expectException(PublicBookingException::class);
        $this->expectExceptionMessage('online_booking.error.invalid_request');
        $service->validateAvailabilityRequest($request);
    }

    /** Ensure requests submitted too quickly are rejected. */
    public function testTooFastRequestIsRejected(): void
    {
        $request = new Request([], [
            'website' => '',
            'form_started_at' => (string) time(),
        ]);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->headers->set('User-Agent', 'PHPUnit');
        $request->headers->set('Accept-Language', 'de');

        $service = new PublicBookingAbuseProtectionService(
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createRequestStackWithSession()
        );

        $this->expectException(PublicBookingException::class);
        $this->expectExceptionMessage('online_booking.error.request_too_fast');
        $service->validateAvailabilityRequest($request);
    }

    /** Ensure missing form_started_at is rejected. */
    public function testMissingFormStartedAtIsRejected(): void
    {
        $request = new Request([], [
            'website' => '',
        ]);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->headers->set('User-Agent', 'PHPUnit');
        $request->headers->set('Accept-Language', 'de');

        $service = new PublicBookingAbuseProtectionService(
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createRequestStackWithSession()
        );

        $this->expectException(PublicBookingException::class);
        $this->expectExceptionMessage('online_booking.error.invalid_request');
        $service->validateAvailabilityRequest($request);
    }

    /** Ensure an invalid submit token is rejected. */
    public function testInvalidSubmitTokenIsRejected(): void
    {
        $requestStack = $this->createRequestStackWithSession();
        $session = $requestStack->getSession();
        $session->set('public_booking_submit_token', 'correct-token');

        $request = new Request([], [
            'website' => '',
            'form_started_at' => (string) (time() - 5),
            'submit_token' => 'wrong-token',
        ]);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->headers->set('User-Agent', 'PHPUnit');
        $request->headers->set('Accept-Language', 'de');

        $service = new PublicBookingAbuseProtectionService(
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createLimiterFactoryReturningAccepted(true),
            $requestStack
        );

        $this->expectException(PublicBookingException::class);
        $this->expectExceptionMessage('online_booking.error.invalid_submit_token');
        $service->validateSubmitRequest($request);
    }

    /** Ensure a submit token cannot be reused (double-submit protection). */
    public function testSubmitTokenCannotBeReused(): void
    {
        $requestStack = $this->createRequestStackWithSession();
        $session = $requestStack->getSession();
        $session->set('public_booking_submit_token', 'one-time-token');

        $request = new Request([], [
            'website' => '',
            'form_started_at' => (string) (time() - 5),
            'submit_token' => 'one-time-token',
        ]);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->headers->set('User-Agent', 'PHPUnit');
        $request->headers->set('Accept-Language', 'de');

        $service = new PublicBookingAbuseProtectionService(
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createLimiterFactoryReturningAccepted(true),
            $requestStack
        );

        // First submit succeeds
        $service->validateSubmitRequest($request);

        // Second submit with same token must fail (token consumed)
        $this->expectException(PublicBookingException::class);
        $this->expectExceptionMessage('online_booking.error.invalid_submit_token');
        $service->validateSubmitRequest($request);
    }

    /** Ensure createFormState returns expected structure with timing and optional submit token. */
    public function testCreateFormStateReturnsExpectedStructure(): void
    {
        $service = new PublicBookingAbuseProtectionService(
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createRequestStackWithSession()
        );

        $stateWithoutToken = $service->createFormState(false);
        self::assertArrayHasKey('formStartedAt', $stateWithoutToken);
        self::assertIsInt($stateWithoutToken['formStartedAt']);
        self::assertNull($stateWithoutToken['submitToken']);

        $stateWithToken = $service->createFormState(true);
        self::assertArrayHasKey('submitToken', $stateWithToken);
        self::assertIsString($stateWithToken['submitToken']);
        self::assertNotEmpty($stateWithToken['submitToken']);
    }

    /** Build a limiter factory mock that always returns a limiter with the requested acceptance state. */
    private function createLimiterFactoryReturningAccepted(bool $accepted): RateLimiterFactoryInterface
    {
        $rateLimit = new RateLimit(
            $accepted ? 1 : 0,
            new \DateTimeImmutable('+1 minute'),
            $accepted,
            1
        );

        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('consume')
            ->willReturn($rateLimit);

        $factory = $this->createStub(RateLimiterFactoryInterface::class);
        $factory->method('create')
            ->willReturn($limiter);

        return $factory;
    }

    /** Create a request stack with an attached mock session used by submit token checks. */
    private function createRequestStackWithSession(): RequestStack
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);

        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }
}
