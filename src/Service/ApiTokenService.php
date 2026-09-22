<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\Enum\ApiScope;
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
    // Tokens for AI assistants must expire; a leaked one then stops working on its own.
    private const MCP_MAX_LIFETIME = '+1 year +1 day';

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
     *
     * @throws \InvalidArgumentException when a token for AI assistants (mcp:access) lacks an expiry
     *                                   or would live longer than one year
     */
    public function createToken(User $user, string $name, array $scopes, ?\DateTimeImmutable $expiresAt): ApiTokenCreationResult
    {
        if (\in_array(ApiScope::MCP_ACCESS->value, $scopes, true)) {
            $latestExpiry = (new \DateTimeImmutable())->modify(self::MCP_MAX_LIFETIME);
            if (null === $expiresAt || $expiresAt > $latestExpiry) {
                throw new \InvalidArgumentException('Tokens for AI assistants must expire within one year.');
            }
        }

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
     * Filters scopes down to those the given roles can back (see ApiScope::requiredRole()).
     *
     * @param list<ApiScope> $scopes
     * @param list<string>   $reachableRoles roles including those inherited through the hierarchy
     *
     * @return list<ApiScope>
     */
    public static function grantableScopes(array $scopes, array $reachableRoles): array
    {
        return array_values(array_filter(
            $scopes,
            static fn (ApiScope $scope): bool => null === $scope->requiredRole() || \in_array($scope->requiredRole(), $reachableRoles, true),
        ));
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
