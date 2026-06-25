<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\PublicBookingException;
use App\Service\PublicBookingAbuseProtectionService;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
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
            $this->createTokenStore()
        );

        $this->expectException(PublicBookingException::class);
        $this->expectExceptionMessage('online_booking.error.try_again_later');
        $service->validateAvailabilityRequest($request);
    }

    /** Ensure submit requests are rejected when the submit limiter denies consumption. */
    public function testValidateSubmitRequestThrowsWhenSubmitRateLimitIsExceeded(): void
    {
        // The limiter is checked before the token, so the token value is irrelevant here.
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
            $this->createTokenStore()
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
            $this->createTokenStore()
        );

        $service->validateAvailabilityRequest($request);
        $this->addToAssertionCount(1);
    }

    /** Ensure a valid submit request with a previously issued token passes all checks. */
    public function testValidateSubmitRequestPassesForValidRequest(): void
    {
        $service = new PublicBookingAbuseProtectionService(
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createTokenStore()
        );

        $token = $service->issueSubmitToken();

        $request = new Request([], [
            'website' => '',
            'form_started_at' => (string) (time() - 5),
            'submit_token' => $token,
        ]);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->headers->set('User-Agent', 'PHPUnit');
        $request->headers->set('Accept-Language', 'de');

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
            $this->createTokenStore()
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
            $this->createTokenStore()
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
            $this->createTokenStore()
        );

        $this->expectException(PublicBookingException::class);
        $this->expectExceptionMessage('online_booking.error.invalid_request');
        $service->validateAvailabilityRequest($request);
    }

    /** Ensure an unknown submit token (never issued) is rejected. */
    public function testInvalidSubmitTokenIsRejected(): void
    {
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
            $this->createTokenStore()
        );

        $this->expectException(PublicBookingException::class);
        $this->expectExceptionMessage('online_booking.error.invalid_submit_token');
        $service->validateSubmitRequest($request);
    }

    /** Ensure a submit token cannot be reused (double-submit protection). */
    public function testSubmitTokenCannotBeReused(): void
    {
        $service = new PublicBookingAbuseProtectionService(
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createTokenStore()
        );

        $token = $service->issueSubmitToken();

        $request = new Request([], [
            'website' => '',
            'form_started_at' => (string) (time() - 5),
            'submit_token' => $token,
        ]);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->headers->set('User-Agent', 'PHPUnit');
        $request->headers->set('Accept-Language', 'de');

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
            $this->createTokenStore()
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

    /** Ensure the fallback notice is suppressed on the first failure and triggered on repeated failures. */
    public function testRegisterSubmitFailureTriggersFallbackAfterRepeatedFailures(): void
    {
        $service = new PublicBookingAbuseProtectionService(
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createTokenStore()
        );

        $request = new Request();
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->headers->set('User-Agent', 'PHPUnit');
        $request->headers->set('Accept-Language', 'de');

        self::assertFalse($service->registerSubmitFailure($request), 'First failure must not trigger the fallback');
        self::assertTrue($service->registerSubmitFailure($request), 'Second failure must trigger the fallback');
    }

    /** Ensure clearing the counter resets the fallback state (e.g. after a successful booking). */
    public function testClearSubmitFailuresResetsCounter(): void
    {
        $service = new PublicBookingAbuseProtectionService(
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createLimiterFactoryReturningAccepted(true),
            $this->createTokenStore()
        );

        $request = new Request();
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->headers->set('User-Agent', 'PHPUnit');
        $request->headers->set('Accept-Language', 'de');

        $service->registerSubmitFailure($request);
        $service->clearSubmitFailures($request);

        self::assertFalse($service->registerSubmitFailure($request), 'Counter must start over after a reset');
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

    /** Create an in-memory, cookie-independent token store used by submit token checks. */
    private function createTokenStore(): CacheItemPoolInterface
    {
        return new ArrayAdapter();
    }
}
