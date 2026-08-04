<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Enum\ApiScope;
use App\Security\ApiTokenContext;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Grants API scope attributes (e.g. API_SCOPE_RESERVATIONS_READ) only when the request
 * was authenticated with an ApiToken that carries the scope AND the token owner holds
 * the underlying application role. A token can never exceed its owner's permissions.
 */
class ApiScopeVoter extends Voter
{
    public const RESERVATIONS_READ = 'API_SCOPE_RESERVATIONS_READ';
    public const CALENDAR_READ = 'API_SCOPE_CALENDAR_READ';
    public const STATISTICS_READ = 'API_SCOPE_STATISTICS_READ';

    private const ATTRIBUTE_SCOPES = [
        self::RESERVATIONS_READ => ApiScope::RESERVATIONS_READ,
        self::CALENDAR_READ => ApiScope::CALENDAR_READ,
        self::STATISTICS_READ => ApiScope::STATISTICS_READ,
    ];

    public function __construct(
        private readonly ApiTokenContext $apiTokenContext,
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return isset(self::ATTRIBUTE_SCOPES[$attribute]);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $apiToken = $this->apiTokenContext->getToken();
        if (null === $apiToken) {
            return false;
        }

        $scope = self::ATTRIBUTE_SCOPES[$attribute];
        if (!$apiToken->hasScope($scope)) {
            return false;
        }

        $reachableRoles = $this->roleHierarchy->getReachableRoleNames($token->getRoleNames());

        return \in_array($scope->requiredRole(), $reachableRoles, true);
    }
}
