<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Enum\ApiScope;
use App\Service\ApiTokenService;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Resolves "Authorization: Bearer fwb_..." headers for the mcp firewall. Only tokens that carry
 * the mcp:access scope authenticate, so REST tokens (e.g. a calendar subscription) never reach
 * the MCP tools.
 */
final class McpTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly ApiTokenService $apiTokenService,
        private readonly ApiTokenContext $apiTokenContext,
    ) {
    }

    public function getUserBadgeFrom(string $accessToken): UserBadge
    {
        $apiToken = $this->apiTokenService->validate($accessToken);
        if (!$apiToken->hasScope(ApiScope::MCP_ACCESS)) {
            throw new BadCredentialsException('Token is not enabled for AI assistants.');
        }

        $this->apiTokenContext->setToken($apiToken);
        $user = $apiToken->getUser();

        return new UserBadge($user->getUserIdentifier(), static fn () => $user);
    }
}
