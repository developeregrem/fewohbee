<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

class PublicBookingAbuseProtectionService
{
    private const SESSION_SUBMIT_TOKEN = 'public_booking_submit_token';
        private const MINIMUM_ELAPSED_SECONDS = 1;

    public function __construct(
        #[Autowire(service: 'limiter.public_booking_availability')]
        private readonly RateLimiterFactoryInterface $availabilityLimiter,
        #[Autowire(service: 'limiter.public_booking_submit')]
        private readonly RateLimiterFactoryInterface $submitLimiter,
        private readonly RequestStack $requestStack
    ) {
    }

    /**
     * Validate the public availability and preview steps with lightweight anti-abuse checks.
     */
    public function validateAvailabilityRequest(Request $request): void
    {
        $this->assertHoneypotIsEmpty($request);
        $this->assertMinimumElapsedTime($request);
        $this->consumeToken($this->availabilityLimiter, $this->buildLimiterKey($request, 'availability'), 'online_booking.error.try_again_later');
    }

    /**
     * Validate the final public submit step and consume the one-time submit token.
     */
    public function validateSubmitRequest(Request $request): void
    {
        $this->assertHoneypotIsEmpty($request);
        $this->assertMinimumElapsedTime($request);
        $this->consumeToken($this->submitLimiter, $this->buildLimiterKey($request, 'submit'), 'online_booking.error.try_again_later_submit');
        $this->consumeSubmitToken($request);
    }

    /**
     * Create the hidden form state used by public forms for timing and one-time submit protection.
     *
     * @return array{formStartedAt: int, submitToken: ?string}
     */
    public function createFormState(bool $includeSubmitToken): array
    {
        return [
            'formStartedAt' => time(),
            'submitToken' => $includeSubmitToken ? $this->issueSubmitToken() : null,
        ];
    }

    /** Store and return a fresh single-use submit token for the current public booking session. */
    public function issueSubmitToken(): string
    {
        $session = $this->requestStack->getSession();
        if (null === $session) {
            return Uuid::v4()->toRfc4122();
        }

        $token = Uuid::v4()->toRfc4122();
        $session->set(self::SESSION_SUBMIT_TOKEN, $token);

        return $token;
    }

    /** Reject requests triggered suspiciously fast after the form was rendered. */
    private function assertMinimumElapsedTime(Request $request): void
    {
        $startedAt = (int) $request->request->get('form_started_at', 0);
        if ($startedAt < 1) {
            throw new \RuntimeException('online_booking.error.invalid_request');
        }

        if ((time() - $startedAt) < self::MINIMUM_ELAPSED_SECONDS) {
            throw new \RuntimeException('online_booking.error.request_too_fast');
        }
    }

    /** Reject bots that fill the hidden honeypot field. */
    private function assertHoneypotIsEmpty(Request $request): void
    {
        if ('' !== trim((string) $request->request->get('website', ''))) {
            throw new \RuntimeException('online_booking.error.invalid_request');
        }
    }

    /** Consume one token from the configured limiter and fail fast when the limit is exceeded. */
    private function consumeToken(RateLimiterFactoryInterface $factory, string $key, string $errorKey): void
    {
        $limit = $factory->create($key)->consume();
        if (!$limit->isAccepted()) {
            throw new \RuntimeException($errorKey);
        }
    }

    /** Ensure the preview-issued submit token matches and cannot be reused. */
    private function consumeSubmitToken(Request $request): void
    {
        $session = $this->requestStack->getSession();
        $submittedToken = trim((string) $request->request->get('submit_token', ''));

        if (null === $session || '' === $submittedToken) {
            throw new \RuntimeException('online_booking.error.invalid_request');
        }

        $storedToken = (string) $session->get(self::SESSION_SUBMIT_TOKEN, '');
        $session->remove(self::SESSION_SUBMIT_TOKEN);

        if ('' === $storedToken || !hash_equals($storedToken, $submittedToken)) {
            throw new \RuntimeException('online_booking.error.invalid_submit_token');
        }
    }

    /** Build a stable limiter key based on client IP and the current public-booking flow step. */
    private function buildLimiterKey(Request $request, string $scope): string
    {
        $ip = (string) ($request->getClientIp() ?? 'unknown');
        $userAgent = mb_strtolower(trim((string) $request->headers->get('User-Agent', 'unknown')));
        $language = mb_strtolower(trim((string) $request->headers->get('Accept-Language', 'unknown')));

        return sprintf(
            '%s:%s',
            $scope,
            hash('sha256', $ip.'|'.$userAgent.'|'.$language)
        );
    }
}
