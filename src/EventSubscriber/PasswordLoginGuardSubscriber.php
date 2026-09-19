<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Oidc\OidcConfiguration;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\RememberMeAuthenticator;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Enforces OIDC_ENFORCE: when single sign-on is mandatory, password login is
 * switched off for real, not just hidden.
 *
 * Removing the form from the login page is presentation only — credentials can
 * still be posted straight to /login. That would leave exactly the passwords the
 * enforce mode exists to retire (often weak, handed out internally in the
 * expectation that "everything goes through SSO anyway") as a live way in. So
 * the rejection happens here, in the authentication pipeline.
 *
 * The check keys on the PasswordCredentials badge, which only form_login
 * produces. Passkey login and the API token authenticators build a
 * SelfValidatingPassport without it and are deliberately left working: the
 * passkey is the second factor an administrator keeps when the identity
 * provider is unavailable.
 */
final class PasswordLoginGuardSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly OidcConfiguration $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [CheckPassportEvent::class => ['onCheckPassport', 2048]];
    }

    /**
     * Reject any authentication attempt that rests on a password, whether it is
     * typed into the form or replayed from a remember-me cookie.
     *
     * Both checks belong here rather than on LoginSuccessEvent: this event is
     * dispatched inside the authenticator's try/catch, so the exception turns
     * into a proper LoginFailureEvent — the token never reaches the token
     * storage, and RememberMeListener clears the cookie on that event. Thrown
     * from LoginSuccessEvent it would escape uncaught, after the token was
     * already stored.
     */
    public function onCheckPassport(CheckPassportEvent $event): void
    {
        if (!$this->config->isEnforced()) {
            return;
        }

        // Remember-me cookies are issued by the password form, so any still in
        // the wild predate the switch and would otherwise keep granting access
        // until they expire.
        if ($event->getAuthenticator() instanceof RememberMeAuthenticator) {
            $this->logger->warning('Remember-me login rejected: single sign-on is enforced (OIDC_ENFORCE).');

            throw new CustomUserMessageAuthenticationException('login.oidc.error.password_disabled');
        }

        if (!$event->getPassport()->hasBadge(PasswordCredentials::class)) {
            return;
        }

        $this->logger->warning('Password login rejected: single sign-on is enforced (OIDC_ENFORCE).');

        throw new CustomUserMessageAuthenticationException('login.oidc.error.password_disabled');
    }
}
