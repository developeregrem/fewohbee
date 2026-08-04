<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

class ApiTokenService
{
    public const TOKEN_PREFIX = 'fwb_';
    private const PREFIX_DISPLAY_LENGTH = 12;
    private const LAST_USED_UPDATE_INTERVAL = 300; // seconds

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ApiTokenRepository $apiTokenRepository,
        #[Autowire(service: 'limiter.api_auth_failure')]
        private readonly RateLimiterFactoryInterface $authFailureLimiter,
        private readonly RequestStack $requestStack,
    ) {
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * @param list<string> $scopes
     */
    public function createToken(User $user, string $name, array $scopes, ?\DateTimeImmutable $expiresAt): ApiTokenCreationResult
    {
        $plainToken = self::TOKEN_PREFIX.bin2hex(random_bytes(32));

        $token = new ApiToken();
        $token->setUser($user)
            ->setName($name)
            ->setScopes($scopes)
            ->setExpiresAt($expiresAt)
            ->setTokenPrefix(substr($plainToken, 0, self::PREFIX_DISPLAY_LENGTH))
            ->setTokenHash(self::hash($plainToken));

        $this->em->persist($token);
        $this->em->flush();

        return new ApiTokenCreationResult($plainToken, $token);
    }

    /**
     * @throws BadCredentialsException when the token is unknown, expired or its owner is inactive
     */
    public function validate(string $plainToken): ApiToken
    {
        if (!str_starts_with($plainToken, self::TOKEN_PREFIX)) {
            $this->registerFailure();
            throw new BadCredentialsException('Invalid API token.');
        }

        $token = $this->apiTokenRepository->findOneByHash(self::hash($plainToken));
        if (null === $token || $token->isExpired() || true !== $token->getUser()->getActive()) {
            $this->registerFailure();
            throw new BadCredentialsException('Invalid API token.');
        }

        $this->touchLastUsed($token);

        return $token;
    }

    private function touchLastUsed(ApiToken $token): void
    {
        $lastUsed = $token->getLastUsedAt();
        $now = new \DateTimeImmutable();
        if (null !== $lastUsed && ($now->getTimestamp() - $lastUsed->getTimestamp()) < self::LAST_USED_UPDATE_INTERVAL) {
            return;
        }
        $token->setLastUsedAt($now);
        $this->em->flush();
    }

    private function registerFailure(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return;
        }
        $limit = $this->authFailureLimiter->create('api-auth-'.($request->getClientIp() ?? 'unknown'))->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyLoginAttemptsAuthenticationException();
        }
    }
}
