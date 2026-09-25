<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use App\Security\ClientFingerprint;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Limits of the public check-in page. Only failures count.
 *
 * Guests often check in at the reception, over the house's WiFi: many devices behind one
 * address, several family members opening the same forwarded link. Legitimate use therefore
 * never consumes anything; what counts is what an attacker produces:
 *
 * - links that do not work, per visitor (guessing tokens),
 * - wrong booking details, per link (guessing the details behind a known link),
 * - stored submissions, per link (a flood of writes through a link someone got hold of).
 *
 * The "is blocked" checks only look and never consume, so a blocked visitor learns nothing
 * about whether the next attempt would have been right.
 */
class GuestCheckInRateLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.guest_checkin_invalid_link')]
        private readonly RateLimiterFactoryInterface $invalidLinkLimiter,
        #[Autowire(service: 'limiter.guest_checkin_verify')]
        private readonly RateLimiterFactoryInterface $verifyLimiter,
        #[Autowire(service: 'limiter.guest_checkin_submit')]
        private readonly RateLimiterFactoryInterface $submitLimiter,
    ) {
    }

    public function isLinkGuessingBlocked(Request $request): bool
    {
        return self::isExhausted($this->invalidLinkLimiter->create(self::clientKey($request)));
    }

    public function registerInvalidLink(Request $request): void
    {
        $this->invalidLinkLimiter->create(self::clientKey($request))->consume();
    }

    public function isVerificationBlocked(string $selector): bool
    {
        return self::isExhausted($this->verifyLimiter->create('guest_checkin_verify:'.$selector));
    }

    public function registerFailedVerification(string $selector): void
    {
        $this->verifyLimiter->create('guest_checkin_verify:'.$selector)->consume();
    }

    public function isSubmissionBlocked(string $selector): bool
    {
        return self::isExhausted($this->submitLimiter->create('guest_checkin_submit:'.$selector));
    }

    public function registerSubmission(string $selector): void
    {
        $this->submitLimiter->create('guest_checkin_submit:'.$selector)->consume();
    }

    private static function clientKey(Request $request): string
    {
        return ClientFingerprint::limiterKey($request, 'guest_checkin_invalid_link');
    }

    /** Consuming zero tokens reports the remaining budget without using any of it. */
    private static function isExhausted(LimiterInterface $limiter): bool
    {
        return 0 === $limiter->consume(0)->getRemainingTokens();
    }
}
