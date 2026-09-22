<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Enum\ApiScope;
use App\Service\ApiTokenService;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Resolves "Authorization: Bearer fwb_..." headers to a user for the api firewall.
 * Tokens for AI assistants (mcp:access) are refused here; see McpTokenHandler.
 */
class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly ApiTokenService $apiTokenService,
        private readonly ApiTokenContext $apiTokenContext,
    ) {
    }

    public function getUserBadgeFrom(string $accessToken): UserBadge
    {
        $apiToken = $this->apiTokenService->validate($accessToken);
        // AI tokens only work at /mcp, where guest data is filtered; the REST API would not.
        if ($apiToken->hasScope(ApiScope::MCP_ACCESS)) {
            throw new BadCredentialsException('Tokens for AI assistants only work at the MCP endpoint.');
        }
        $this->apiTokenContext->setToken($apiToken);
        $user = $apiToken->getUser();

        return new UserBadge($user->getUserIdentifier(), static fn () => $user);
    }
}
