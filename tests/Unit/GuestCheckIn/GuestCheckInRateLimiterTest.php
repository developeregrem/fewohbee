<?php

declare(strict_types=1);

namespace App\Tests\Unit\GuestCheckIn;

use App\Service\GuestCheckIn\GuestCheckInRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class GuestCheckInRateLimiterTest extends TestCase
{
    public function testLookingDoesNotUseUpTheBudget(): void
    {
        $limiter = $this->limiter();

        for ($i = 0; $i < 50; ++$i) {
            self::assertFalse($limiter->isVerificationBlocked('link'));
            self::assertFalse($limiter->isLinkGuessingBlocked($this->request()));
            self::assertFalse($limiter->isSubmissionBlocked('link'));
        }
    }

    public function testWrongBookingDetailsBlockOnlyThatLink(): void
    {
        $limiter = $this->limiter();

        for ($i = 0; $i < 5; ++$i) {
            $limiter->registerFailedVerification('link');
        }

        self::assertTrue($limiter->isVerificationBlocked('link'));
        self::assertFalse($limiter->isVerificationBlocked('other-link'));
    }

    public function testInvalidLinksBlockOnlyTheVisitorWhoProducesThem(): void
    {
        $limiter = $this->limiter();
        $guesser = $this->request('198.51.100.7', 'curl/8');

        for ($i = 0; $i < 20; ++$i) {
            $limiter->registerInvalidLink($guesser);
        }

        self::assertTrue($limiter->isLinkGuessingBlocked($guesser));
        // Same hotel WiFi address, but a guest's phone.
        self::assertFalse($limiter->isLinkGuessingBlocked($this->request('198.51.100.7', 'Mozilla/5.0 (iPhone)')));
    }

    private function limiter(): GuestCheckInRateLimiter
    {
        $factory = static fn (string $id, int $limit, string $interval): RateLimiterFactory => new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => $interval],
            new InMemoryStorage(),
        );

        return new GuestCheckInRateLimiter(
            $factory('invalid', 20, '15 minutes'),
            $factory('verify', 5, '15 minutes'),
            $factory('submit', 10, '1 hour'),
        );
    }

    private function request(string $ip = '198.51.100.7', string $userAgent = 'Mozilla/5.0'): Request
    {
        return new Request(server: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $userAgent]);
    }
}
