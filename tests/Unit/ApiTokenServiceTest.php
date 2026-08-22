<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ApiToken;
use App\Entity\Enum\ApiScope;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Service\ApiTokenService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

final class ApiTokenServiceTest extends TestCase
{
    public function testCreateTokenFormatAndHash(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');
        $service = $this->buildService($em);

        $user = $this->buildUser(true);
        $result = $service->createToken($user, 'Test', [ApiScope::RESERVATIONS_READ->value], null);

        self::assertMatchesRegularExpression('/^fwb_[0-9a-f]{64}$/', $result->plainToken);
        self::assertSame(substr($result->plainToken, 0, 12), $result->token->getTokenPrefix());
        self::assertSame(hash('sha256', $result->plainToken), $result->token->getTokenHash());
        self::assertTrue($result->token->hasScope(ApiScope::RESERVATIONS_READ));
        self::assertFalse($result->token->hasScope(ApiScope::CALENDAR_READ));
        self::assertNull($result->token->getExpiresAt());
    }

    public function testValidateRejectsWrongPrefix(): void
    {
        $service = $this->buildService();

        $this->expectException(BadCredentialsException::class);
        $service->validate('not-a-token');
    }

    public function testValidateRejectsUnknownToken(): void
    {
        $service = $this->buildService(repositoryResult: null);

        $this->expectException(BadCredentialsException::class);
        $service->validate('fwb_'.str_repeat('a', 64));
    }

    public function testValidateRejectsExpiredToken(): void
    {
        $token = $this->buildToken($this->buildUser(true));
        $token->setExpiresAt(new \DateTimeImmutable('-1 day'));
        $service = $this->buildService(repositoryResult: $token);

        $this->expectException(BadCredentialsException::class);
        $service->validate('fwb_'.str_repeat('a', 64));
    }

    public function testValidateRejectsInactiveUser(): void
    {
        $token = $this->buildToken($this->buildUser(false));
        $service = $this->buildService(repositoryResult: $token);

        $this->expectException(BadCredentialsException::class);
        $service->validate('fwb_'.str_repeat('a', 64));
    }

    public function testValidateAcceptsValidTokenAndSetsLastUsed(): void
    {
        $token = $this->buildToken($this->buildUser(true));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $service = $this->buildService($em, $token);

        $validated = $service->validate('fwb_'.str_repeat('a', 64));

        self::assertSame($token, $validated);
        self::assertNotNull($token->getLastUsedAt());
    }

    public function testValidateThrottlesLastUsedUpdates(): void
    {
        $token = $this->buildToken($this->buildUser(true));
        $recentlyUsed = new \DateTimeImmutable('-10 seconds');
        $token->setLastUsedAt($recentlyUsed);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $service = $this->buildService($em, $token);

        $service->validate('fwb_'.str_repeat('a', 64));

        self::assertSame($recentlyUsed, $token->getLastUsedAt());
    }

    public function testIsExpiredIsFalseWithoutExpiry(): void
    {
        $token = new ApiToken();
        self::assertFalse($token->isExpired());
        $token->setExpiresAt(new \DateTimeImmutable('+1 day'));
        self::assertFalse($token->isExpired());
        $token->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        self::assertTrue($token->isExpired());
    }

    private function buildService(?EntityManagerInterface $em = null, ?ApiToken $repositoryResult = null): ApiTokenService
    {
        $repository = $this->createStub(ApiTokenRepository::class);
        $repository->method('findOneByHash')->willReturn($repositoryResult);

        // Empty request stack: the auth-failure limiter is skipped outside a request context.
        return new ApiTokenService(
            $em ?? $this->createStub(EntityManagerInterface::class),
            $repository,
            $this->createStub(RateLimiterFactoryInterface::class),
            new RequestStack()
        );
    }

    private function buildUser(bool $active): User
    {
        $user = new User();
        $user->setUsername('tester');
        $user->setActive($active);

        return $user;
    }

    private function buildToken(User $user): ApiToken
    {
        $token = new ApiToken();
        $token->setUser($user)
            ->setName('Test')
            ->setScopes([ApiScope::RESERVATIONS_READ->value])
            ->setTokenPrefix('fwb_aaaaaaaa')
            ->setTokenHash(hash('sha256', 'fwb_'.str_repeat('a', 64)));

        return $token;
    }
}
