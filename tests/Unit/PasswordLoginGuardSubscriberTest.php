<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\EventSubscriber\PasswordLoginGuardSubscriber;
use App\Service\Oidc\OidcConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\RememberMeAuthenticator;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Covers the server-side half of OIDC_ENFORCE. Hiding the form is presentation
 * only — these tests pin down that credentials are actually refused, and that
 * the other login methods keep working.
 */
final class PasswordLoginGuardSubscriberTest extends TestCase
{
    private static function subscriber(bool $enforce, bool $oidcEnabled = true): PasswordLoginGuardSubscriber
    {
        $config = new OidcConfiguration(
            $oidcEnabled, 'https://id.example.com', 'fewohbee', 's3cr3t', 'openid email', '',
            'email', true, $enforce, false,
        );

        return new PasswordLoginGuardSubscriber($config, new NullLogger());
    }

    private static function passwordPassport(): Passport
    {
        return new Passport(
            new UserBadge('staff', static fn (): object => new InMemoryUser('staff', 'hash')),
            new PasswordCredentials('hunter2'),
        );
    }

    private static function passkeyPassport(): SelfValidatingPassport
    {
        return new SelfValidatingPassport(
            new UserBadge('staff', static fn (): object => new InMemoryUser('staff', null))
        );
    }

    private static function checkPassportEvent(Passport $passport, ?AuthenticatorInterface $authenticator = null): CheckPassportEvent
    {
        return new CheckPassportEvent($authenticator ?? self::createStub(AuthenticatorInterface::class), $passport);
    }

    public function testPasswordLoginPassesWhenEnforceIsOff(): void
    {
        self::subscriber(enforce: false)->onCheckPassport(self::checkPassportEvent(self::passwordPassport()));

        $this->expectNotToPerformAssertions();
    }

    public function testPasswordLoginIsRejectedWhenEnforced(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('login.oidc.error.password_disabled');

        self::subscriber(enforce: true)->onCheckPassport(self::checkPassportEvent(self::passwordPassport()));
    }

    /**
     * A stray OIDC_ENFORCE without a configured provider would otherwise lock
     * every user out.
     */
    public function testPasswordLoginPassesWhenSingleSignOnIsNotConfigured(): void
    {
        self::subscriber(enforce: true, oidcEnabled: false)
            ->onCheckPassport(self::checkPassportEvent(self::passwordPassport()));

        $this->expectNotToPerformAssertions();
    }

    /**
     * Passkey and API token authenticators build a passport without password
     * credentials — they stay usable, which is what keeps an administrator from
     * being stranded when the identity provider is down.
     */
    public function testPasswordlessLoginsAreUntouchedWhenEnforced(): void
    {
        self::subscriber(enforce: true)->onCheckPassport(self::checkPassportEvent(self::passkeyPassport()));

        $this->expectNotToPerformAssertions();
    }

    /**
     * Remember-me cookies are issued by the password form, so any still in the
     * wild predate the switch and would otherwise keep granting access.
     *
     * The rejection has to happen on CheckPassportEvent: it is dispatched
     * inside the authenticator manager's try/catch, so it becomes a proper
     * LoginFailureEvent — no token is stored and RememberMeListener clears the
     * cookie. On LoginSuccessEvent the token would already be in the storage.
     */
    public function testRememberMeLoginIsRejectedWhenEnforced(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('login.oidc.error.password_disabled');

        self::subscriber(enforce: true)->onCheckPassport(
            self::checkPassportEvent(self::passkeyPassport(), self::createStub(RememberMeAuthenticator::class))
        );
    }

    public function testTheGuardOnlyListensToCheckPassport(): void
    {
        self::assertSame(
            [CheckPassportEvent::class],
            array_keys(PasswordLoginGuardSubscriber::getSubscribedEvents()),
        );
    }

    public function testRememberMeLoginPassesWhenEnforceIsOff(): void
    {
        self::subscriber(enforce: false)->onCheckPassport(
            self::checkPassportEvent(self::passkeyPassport(), self::createStub(RememberMeAuthenticator::class))
        );

        $this->expectNotToPerformAssertions();
    }
}
