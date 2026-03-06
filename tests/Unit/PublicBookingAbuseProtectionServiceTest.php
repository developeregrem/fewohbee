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
