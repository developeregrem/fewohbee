<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\ApiTokenService;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Resolves "Authorization: Bearer fwb_..." headers to a user for the api firewall.
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
        $this->apiTokenContext->setToken($apiToken);
        $user = $apiToken->getUser();

        return new UserBadge($user->getUserIdentifier(), static fn () => $user);
    }
}
