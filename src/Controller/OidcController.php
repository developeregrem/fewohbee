<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\OidcAuthenticator;
use App\Service\Oidc\OidcClient;
use App\Service\Oidc\OidcConfiguration;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * Entry points for OpenID Connect single sign-on.
 *
 * Both routes live under /login so the existing PUBLIC_ACCESS rule for ^/login
 * covers them, exactly as it does for the passkey endpoints.
 *
 * There is no CSRF token on these routes: the OIDC "state" parameter is the
 * cross-site request forgery defence for the authorization code flow, and it is
 * checked by OidcAuthenticator before anything else happens.
 */
final class OidcController extends AbstractController
{
    public function __construct(
        private readonly OidcConfiguration $config,
        private readonly OidcClient $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Start the flow: generate state, nonce and PKCE verifier, keep them in the
     * session and send the browser to the identity provider.
     */
    #[Route('/login/oidc/start', name: 'oidc_start', methods: ['GET'])]
    public function start(Request $request): Response
    {
        if (!$this->config->isEnabled()) {
            throw $this->createNotFoundException('Single sign-on is not enabled.');
        }

        // Built from the incoming request. Behind a reverse proxy this only
        // yields the public URL when TRUSTED_PROXIES is configured, so that the
        // X-Forwarded-* headers are honoured.
        $redirectUri = $this->generateUrl('oidc_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);

        try {
            $authorizationRequest = $this->client->createAuthorizationRequest($redirectUri);
        } catch (\Throwable $e) {
            // A misconfigured provider must land the user back on the login page
            // with a readable message instead of a stack trace.
            $this->logger->error('Could not start the OIDC login flow: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            $request->getSession()->set(
                SecurityRequestAttributes::AUTHENTICATION_ERROR,
                new CustomUserMessageAuthenticationException('login.oidc.error.disabled')
            );

            return $this->redirectToRoute('login');
        }

        $session = $request->getSession();
        $session->set(OidcAuthenticator::SESSION_STATE, $authorizationRequest->state);
        $session->set(OidcAuthenticator::SESSION_NONCE, $authorizationRequest->nonce);
        $session->set(OidcAuthenticator::SESSION_CODE_VERIFIER, $authorizationRequest->codeVerifier);
        $session->set(OidcAuthenticator::SESSION_REDIRECT_URI, $authorizationRequest->redirectUri);

        return new RedirectResponse($authorizationRequest->authorizationUrl);
    }

    /**
     * Normally intercepted by OidcAuthenticator, so this body never runs. It is
     * reached only when the authenticator declines the request — single sign-on
     * switched off, or a configuration too incomplete to act on. Returning a
     * Response keeps that case a clean 404 instead of a "controller returned no
     * response" error page.
     */
    #[Route('/login/oidc/callback', name: 'oidc_callback', methods: ['GET'])]
    public function callback(): Response
    {
        throw $this->createNotFoundException('Single sign-on is not enabled.');
    }
}
