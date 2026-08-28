<?php

declare(strict_types=1);

namespace App\Tests\Unit\Oidc;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Oidc\OidcConfiguration;
use App\Service\Oidc\OidcUserResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

final class OidcUserResolverTest extends TestCase
{
    private const ISSUER = 'https://id.example.com';
    private const SUBJECT = 'provider-subject-42';

    private static function user(string $username = 'staff', string $email = 'staff@example.com', bool $active = true): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setActive($active);

        return $user;
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return array<string, mixed>
     */
    private static function claims(array $claims = []): array
    {
        return array_merge([
            'iss' => self::ISSUER,
            'sub' => self::SUBJECT,
            'email' => 'staff@example.com',
            'email_verified' => true,
            'preferred_username' => 'staff',
        ], $claims);
    }

    private function resolver(
        UserRepository $repository,
        string $matching = 'email',
        bool $requireVerifiedEmail = true,
    ): OidcUserResolver {
        $config = new OidcConfiguration(
            true, self::ISSUER, 'fewohbee', 's3cr3t', 'openid email', '',
            $matching, $requireVerifiedEmail, false, false,
        );

        return new OidcUserResolver($config, $repository, $this->createStub(EntityManagerInterface::class), new NullLogger());
    }

    public function testAnAlreadyLinkedAccountResolvesBySubject(): void
    {
        $user = self::user();
        $user->linkOidcIdentity(self::ISSUER, self::SUBJECT);

        $repository = $this->createStub(UserRepository::class);
        $repository->method('findOneByOidcIdentity')->willReturn($user);

        self::assertSame($user, $this->resolver($repository)->resolve(self::claims()));
    }

    /**
     * The binding wins over the e-mail claim, so a changed address at the
     * provider neither breaks the login nor redirects it to another account.
     */
    public function testTheSubjectBindingWinsOverAChangedEmail(): void
    {
        $user = self::user(email: 'old-address@example.com');
        $user->linkOidcIdentity(self::ISSUER, self::SUBJECT);

        $repository = $this->createMock(UserRepository::class);
        $repository->method('findOneByOidcIdentity')->willReturn($user);
        $repository->expects(self::never())->method('findByEmailAddress');

        self::assertSame($user, $this->resolver($repository)->resolve(self::claims(['email' => 'new-address@example.com'])));
    }

    public function testFirstSignInLinksTheMatchingAccount(): void
    {
        $user = self::user();

        $repository = $this->createStub(UserRepository::class);
        $repository->method('findOneByOidcIdentity')->willReturn(null);
        $repository->method('findByEmailAddress')->willReturn([$user]);

        $resolved = $this->resolver($repository)->resolve(self::claims());

        self::assertSame($user, $resolved);
        self::assertTrue($resolved->isLinkedToOidc());
        self::assertSame(self::ISSUER, $resolved->getOidcIssuer());
        self::assertSame(self::SUBJECT, $resolved->getOidcSubject());
    }

    /**
     * users.email carries no unique constraint, so this is reachable — and
     * picking one of the candidates would hand over whichever account the
     * database happened to return first.
     */
    public function testRefusesAnAmbiguousEmailMatch(): void
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findOneByOidcIdentity')->willReturn(null);
        $repository->method('findByEmailAddress')->willReturn([self::user('a'), self::user('b')]);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('login.oidc.error.ambiguous');

        $this->resolver($repository)->resolve(self::claims());
    }

    public function testRefusesWhenNoLocalAccountExists(): void
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findOneByOidcIdentity')->willReturn(null);
        $repository->method('findByEmailAddress')->willReturn([]);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('login.oidc.error.no_match');

        $this->resolver($repository)->resolve(self::claims());
    }

    /**
     * Providers that let a user set an arbitrary unverified address would
     * otherwise allow taking over a colleague's account.
     */
    public function testRefusesAnUnverifiedEmail(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findOneByOidcIdentity')->willReturn(null);
        $repository->expects(self::never())->method('findByEmailAddress');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('login.oidc.error.email_unverified');

        $this->resolver($repository)->resolve(self::claims(['email_verified' => false]));
    }

    public function testAcceptsUnverifiedEmailWhenTheCheckIsTurnedOff(): void
    {
        $user = self::user();
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findOneByOidcIdentity')->willReturn(null);
        $repository->method('findByEmailAddress')->willReturn([$user]);

        $resolved = $this->resolver($repository, requireVerifiedEmail: false)
            ->resolve(self::claims(['email_verified' => false]));

        self::assertSame($user, $resolved);
    }

    /**
     * Some providers send the string "true" rather than a JSON boolean.
     */
    public function testAcceptsAStringEmailVerifiedClaim(): void
    {
        $user = self::user();
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findOneByOidcIdentity')->willReturn(null);
        $repository->method('findByEmailAddress')->willReturn([$user]);

        self::assertSame($user, $this->resolver($repository)->resolve(self::claims(['email_verified' => 'true'])));
    }

    public function testRefusesAnAccountBoundToADifferentSubject(): void
    {
        $user = self::user();
        $user->linkOidcIdentity(self::ISSUER, 'someone-else');

        $repository = $this->createStub(UserRepository::class);
        $repository->method('findOneByOidcIdentity')->willReturn(null);
        $repository->method('findByEmailAddress')->willReturn([$user]);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('login.oidc.error.already_linked');

        $this->resolver($repository)->resolve(self::claims());
    }

    public function testRefusesADeactivatedAccount(): void
    {
        $user = self::user(active: false);
        $user->linkOidcIdentity(self::ISSUER, self::SUBJECT);

        $repository = $this->createStub(UserRepository::class);
        $repository->method('findOneByOidcIdentity')->willReturn($user);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('login.oidc.error.inactive');

        $this->resolver($repository)->resolve(self::claims());
    }

    public function testMatchesByUsernameWhenConfigured(): void
    {
        $user = self::user();

        $repository = $this->createMock(UserRepository::class);
        $repository->method('findOneByOidcIdentity')->willReturn(null);
        $repository->expects(self::never())->method('findByEmailAddress');
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['username' => 'staff'])
            ->willReturn($user);

        self::assertSame($user, $this->resolver($repository, matching: 'username')->resolve(self::claims()));
    }

    /**
     * Providers that keep the ID token minimal (Authelia, Okta) simply do not
     * send the claim we match on, and the login has to top up from UserInfo
     * instead of failing.
     */
    public function testUserInfoIsNeededWhenTheMatchingClaimIsMissing(): void
    {
        $resolver = $this->resolver($this->createStub(UserRepository::class));

        self::assertTrue($resolver->needsUserInfo(['sub' => 'x', 'iss' => self::ISSUER]));
        self::assertTrue($resolver->needsUserInfo(self::claims(['email' => ''])));
        self::assertFalse($resolver->needsUserInfo(self::claims()));
    }

    public function testUserInfoIsNeededWhenOnlyTheVerificationFlagIsMissing(): void
    {
        $claims = self::claims();
        unset($claims['email_verified']);

        self::assertTrue($this->resolver($this->createStub(UserRepository::class))->needsUserInfo($claims));
        // Without the requirement the address on its own is enough.
        self::assertFalse(
            $this->resolver($this->createStub(UserRepository::class), requireVerifiedEmail: false)->needsUserInfo($claims)
        );
    }

    public function testUsernameMatchingLooksAtTheUsernameClaim(): void
    {
        $resolver = $this->resolver($this->createStub(UserRepository::class), matching: 'username');

        self::assertFalse($resolver->needsUserInfo(['preferred_username' => 'staff']));
        self::assertTrue($resolver->needsUserInfo(['email' => 'staff@example.com']));
    }

    public function testRefusesClaimsWithoutASubject(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('login.oidc.error.token');

        $this->resolver($this->createStub(UserRepository::class))->resolve(self::claims(['sub' => null]));
    }
}
