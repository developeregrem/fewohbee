<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ApiToken;
use App\Entity\Enum\ApiScope;
use App\Entity\User;
use App\Security\ApiTokenContext;
use App\Security\Voter\ApiScopeVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

final class ApiScopeVoterTest extends TestCase
{
    public function testDeniesWithoutApiToken(): void
    {
        $voter = $this->buildVoter(new ApiTokenContext());
        $result = $voter->vote($this->buildSecurityToken(['ROLE_ADMIN']), null, [ApiScopeVoter::RESERVATIONS_READ]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testDeniesWhenScopeMissing(): void
    {
        $context = $this->buildContext([ApiScope::CALENDAR_READ->value]);
        $voter = $this->buildVoter($context);
        $result = $voter->vote($this->buildSecurityToken(['ROLE_ADMIN']), null, [ApiScopeVoter::RESERVATIONS_READ]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testDeniesWhenUserLacksUnderlyingRole(): void
    {
        $context = $this->buildContext([ApiScope::RESERVATIONS_READ->value]);
        $voter = $this->buildVoter($context);
        $result = $voter->vote($this->buildSecurityToken(['ROLE_CASHJOURNAL']), null, [ApiScopeVoter::RESERVATIONS_READ]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testGrantsWithScopeAndRole(): void
    {
        $context = $this->buildContext([ApiScope::RESERVATIONS_READ->value]);
        $voter = $this->buildVoter($context);
        $result = $voter->vote($this->buildSecurityToken(['ROLE_RESERVATIONS_RO']), null, [ApiScopeVoter::RESERVATIONS_READ]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testGrantsViaRoleHierarchy(): void
    {
        $context = $this->buildContext([ApiScope::RESERVATIONS_READ->value, ApiScope::CALENDAR_READ->value]);
        $voter = $this->buildVoter($context);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->buildSecurityToken(['ROLE_ADMIN']), null, [ApiScopeVoter::RESERVATIONS_READ])
        );
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->buildSecurityToken(['ROLE_ADMIN']), null, [ApiScopeVoter::CALENDAR_READ])
        );
    }

    public function testAbstainsForUnknownAttribute(): void
    {
        $voter = $this->buildVoter(new ApiTokenContext());
        $result = $voter->vote($this->buildSecurityToken(['ROLE_ADMIN']), null, ['ROLE_ADMIN']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    private function buildVoter(ApiTokenContext $context): ApiScopeVoter
    {
        // Mirrors the role_hierarchy in security.yaml.
        $hierarchy = new RoleHierarchy([
            'ROLE_RESERVATIONS' => ['ROLE_RESERVATIONS_RO'],
            'ROLE_ADMIN' => ['ROLE_RESERVATIONS', 'ROLE_CUSTOMERS', 'ROLE_INVOICES', 'ROLE_STATISTICS', 'ROLE_CASHJOURNAL', 'ROLE_OPERATIONS'],
        ]);

        return new ApiScopeVoter($context, $hierarchy);
    }

    /**
     * @param list<string> $scopes
     */
    private function buildContext(array $scopes): ApiTokenContext
    {
        $user = new User();
        $user->setUsername('tester');
        $user->setActive(true);

        $token = new ApiToken();
        $token->setUser($user)->setName('Test')->setScopes($scopes)
            ->setTokenPrefix('fwb_aaaaaaaa')->setTokenHash(str_repeat('a', 64));

        $context = new ApiTokenContext();
        $context->setToken($token);

        return $context;
    }

    /**
     * @param list<string> $roles
     */
    private function buildSecurityToken(array $roles): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getRoleNames')->willReturn($roles);

        return $token;
    }
}
