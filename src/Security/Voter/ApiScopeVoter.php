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
    public const INVOICES_READ = 'API_SCOPE_INVOICES_READ';
    public const PRICES_READ = 'API_SCOPE_PRICES_READ';
    public const TOURIST_TAX_READ = 'API_SCOPE_TOURIST_TAX_READ';
    public const SUBSIDIARIES_READ = 'API_SCOPE_SUBSIDIARIES_READ';
    public const MCP_ACCESS = 'API_SCOPE_MCP_ACCESS';
    public const GUESTS_READ = 'API_SCOPE_GUESTS_READ';
    public const RESERVATIONS_WRITE = 'API_SCOPE_RESERVATIONS_WRITE';

    private const ATTRIBUTE_SCOPES = [
        self::RESERVATIONS_READ => ApiScope::RESERVATIONS_READ,
        self::CALENDAR_READ => ApiScope::CALENDAR_READ,
        self::STATISTICS_READ => ApiScope::STATISTICS_READ,
        self::INVOICES_READ => ApiScope::INVOICES_READ,
        self::PRICES_READ => ApiScope::PRICES_READ,
        self::TOURIST_TAX_READ => ApiScope::TOURIST_TAX_READ,
        self::SUBSIDIARIES_READ => ApiScope::SUBSIDIARIES_READ,
        self::MCP_ACCESS => ApiScope::MCP_ACCESS,
        self::GUESTS_READ => ApiScope::GUESTS_READ,
        self::RESERVATIONS_WRITE => ApiScope::RESERVATIONS_WRITE,
    ];

    public function __construct(
        private readonly ApiTokenContext $apiTokenContext,
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    /** The voter attribute for a scope, e.g. API_SCOPE_RESERVATIONS_READ. */
    public static function attributeFor(ApiScope $scope): string
    {
        return 'API_SCOPE_'.$scope->name;
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

        $requiredRole = $scope->requiredRole();
        if (null === $requiredRole) {
            return true;
        }

        $reachableRoles = $this->roleHierarchy->getReachableRoleNames($token->getRoleNames());

        return \in_array($requiredRole, $reachableRoles, true);
    }
}
