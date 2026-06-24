<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\PublicBookingException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

class PublicBookingAbuseProtectionService
{
    private const SUBMIT_TOKEN_CACHE_PREFIX = 'public_booking_submit_token_';
    private const SUBMIT_TOKEN_TTL_SECONDS = 7200;
    private const MINIMUM_ELAPSED_SECONDS = 1;

    public function __construct(
        #[Autowire(service: 'limiter.public_booking_availability')]
        private readonly RateLimiterFactoryInterface $availabilityLimiter,
        #[Autowire(service: 'limiter.public_booking_submit')]
        private readonly RateLimiterFactoryInterface $submitLimiter,
        // Server-side, cookie-independent token store. Using the shared cache
        // pool (not the session) is essential for the embedded booking form:
        // when the iframe lives on a third-party domain, the session cookie is
        // not sent on the cross-site submit POST (SameSite=Lax), so a session
        // bound token would always be lost between the preview and submit step.
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $submitTokenStore,
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

    /** Store and return a fresh single-use submit token for the current public booking flow. */
    public function issueSubmitToken(): string
    {
        $token = Uuid::v4()->toRfc4122();

        $item = $this->submitTokenStore->getItem($this->submitTokenCacheKey($token));
        $item->set(true);
        $item->expiresAfter(self::SUBMIT_TOKEN_TTL_SECONDS);
        $this->submitTokenStore->save($item);

        return $token;
    }

    /** Reject requests triggered suspiciously fast after the form was rendered. */
    private function assertMinimumElapsedTime(Request $request): void
    {
        $startedAt = (int) $request->request->get('form_started_at', 0);
        if ($startedAt < 1) {
            throw new PublicBookingException('online_booking.error.invalid_request');
        }

        if ((time() - $startedAt) < self::MINIMUM_ELAPSED_SECONDS) {
            throw new PublicBookingException('online_booking.error.request_too_fast');
        }
    }

    /** Reject bots that fill the hidden honeypot field. */
    private function assertHoneypotIsEmpty(Request $request): void
    {
        if ('' !== trim((string) $request->request->get('website', ''))) {
            throw new PublicBookingException('online_booking.error.invalid_request');
        }
    }

    /** Consume one token from the configured limiter and fail fast when the limit is exceeded. */
    private function consumeToken(RateLimiterFactoryInterface $factory, string $key, string $errorKey): void
    {
        $limit = $factory->create($key)->consume();
        if (!$limit->isAccepted()) {
            throw new PublicBookingException($errorKey);
        }
    }

    /** Ensure the preview-issued submit token exists and cannot be reused (single-use). */
    private function consumeSubmitToken(Request $request): void
    {
        $submittedToken = trim((string) $request->request->get('submit_token', ''));
        if ('' === $submittedToken) {
            throw new PublicBookingException('online_booking.error.invalid_submit_token');
        }

        $cacheKey = $this->submitTokenCacheKey($submittedToken);
        if (!$this->submitTokenStore->getItem($cacheKey)->isHit()) {
            throw new PublicBookingException('online_booking.error.invalid_submit_token');
        }

        // Delete on first use so the token cannot be replayed or double-submitted.
        $this->submitTokenStore->deleteItem($cacheKey);
    }

    /** Build a PSR-6 safe cache key for a submit token (hashed to neutralize arbitrary input). */
    private function submitTokenCacheKey(string $token): string
    {
        return self::SUBMIT_TOKEN_CACHE_PREFIX.hash('sha256', $token);
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
