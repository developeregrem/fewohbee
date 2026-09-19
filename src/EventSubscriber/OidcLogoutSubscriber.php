<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\OidcAuthenticator;
use App\Service\Oidc\OidcConfiguration;
use App\Service\Oidc\OidcDiscoveryService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Optional RP-initiated logout (OIDC_END_SESSION): after clearing the local
 * session, send the user on to the identity provider's end session endpoint.
 *
 * Without this, logging out of FewohBee leaves the provider session intact and
 * the next click on the SSO button signs the user straight back in, which reads
 * as "logout is broken" on a shared machine.
 */
final class OidcLogoutSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly OidcConfiguration $config,
        private readonly OidcDiscoveryService $discovery,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Between DefaultLogoutListener (64), which builds the redirect we
        // replace, and SessionLogoutListener (0), which invalidates the session
        // we still have to read the SSO markers from.
        return [LogoutEvent::class => ['onLogout', 32]];
    }

    /**
     * Replaces the logout response with a redirect to the provider's end
     * session endpoint. Runs while the session is still readable — once
     * SessionLogoutListener has invalidated it, the SSO markers are gone.
     */
    public function onLogout(LogoutEvent $event): void
    {
        if (!$this->config->isEndSessionEnabled()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        // Only users who actually arrived via SSO have a provider session to end.
        if (true !== $session->get(OidcAuthenticator::SESSION_AUTHENTICATED)) {
            return;
        }

        $idToken = $session->get(OidcAuthenticator::SESSION_ID_TOKEN);
        $session->remove(OidcAuthenticator::SESSION_AUTHENTICATED);
        $session->remove(OidcAuthenticator::SESSION_ID_TOKEN);

        try {
            $endSessionEndpoint = $this->discovery->getMetadata()->endSessionEndpoint;
        } catch (\Throwable $e) {
            // A provider we cannot reach must not break logging out locally.
            $this->logger->warning('Could not resolve the end session endpoint; logging out locally only.', ['exception' => $e]);

            return;
        }

        if (null === $endSessionEndpoint) {
            return;
        }

        $query = [
            'client_id' => $this->config->getClientId(),
            'post_logout_redirect_uri' => $this->urlGenerator->generate('login', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
        if (is_string($idToken) && '' !== $idToken) {
            $query['id_token_hint'] = $idToken;
        }

        $separator = str_contains($endSessionEndpoint, '?') ? '&' : '?';
        $event->setResponse(new RedirectResponse(
            $endSessionEndpoint.$separator.http_build_query($query, '', '&', \PHP_QUERY_RFC3986)
        ));
    }
}
