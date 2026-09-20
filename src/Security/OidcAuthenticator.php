<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\Oidc\OidcClient;
use App\Service\Oidc\OidcConfiguration;
use App\Service\Oidc\OidcIdTokenValidator;
use App\Service\Oidc\OidcUserResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Symfony Security authenticator for OpenID Connect single sign-on.
 *
 * Handles the redirect back from the identity provider: validates the one-time
 * values stashed when the flow started, redeems the authorization code, verifies
 * the ID token and resolves the local account.
 *
 * Works alongside form_login and the passkey authenticator — the entry_point
 * stays on form_login, so an unauthenticated user still lands on the login page
 * and chooses a method there.
 */
final class OidcAuthenticator extends AbstractAuthenticator
{
    use TargetPathTrait;

    public const CALLBACK_PATH = '/login/oidc/callback';

    /** Session keys holding the single-use values of an in-flight login. */
    public const SESSION_STATE = 'oidc_state';
    public const SESSION_NONCE = 'oidc_nonce';
    public const SESSION_CODE_VERIFIER = 'oidc_code_verifier';
    public const SESSION_REDIRECT_URI = 'oidc_redirect_uri';
    /** Set after a successful sign-in; drives RP-initiated logout. */
    public const SESSION_AUTHENTICATED = 'oidc_authenticated';
    public const SESSION_ID_TOKEN = 'oidc_id_token';

    public function __construct(
        private readonly OidcConfiguration $config,
        private readonly OidcClient $client,
        private readonly OidcIdTokenValidator $idTokenValidator,
        private readonly OidcUserResolver $userResolver,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'limiter.oidc_callback')]
        private readonly RateLimiterFactoryInterface $oidcCallbackLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Only handle the provider's redirect back to us, and only while single
     * sign-on is configured.
     */
    public function supports(Request $request): bool
    {
        return $this->config->isEnabled()
            && $request->isMethod('GET')
            && self::CALLBACK_PATH === $request->getPathInfo();
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $session = $request->getSession();

        // The state is checked first, against the session value left in place.
        // A forged callback therefore costs nothing: it triggers no outbound
        // request, consumes no rate limit budget, and - crucially - cannot
        // cancel a login the real user has in flight. Providers echo state on
        // error responses too (RFC 6749 4.1.2.1), so this also covers the
        // "user cancelled" case below.
        $state = $request->query->get('state');
        $expectedState = $session->get(self::SESSION_STATE);
        if (!is_string($expectedState) || !is_string($state) || !hash_equals($expectedState, $state)) {
            throw new CustomUserMessageAuthenticationException('login.oidc.error.state');
        }

        // State matched, so this callback belongs to our own login attempt: the
        // one-time values are spent from here on, whatever the outcome.
        $expected = $this->takeSessionValues($session);

        $limit = $this->oidcCallbackLimiter->create($request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyLoginAttemptsAuthenticationException();
        }

        // The provider reports user cancellation and consent errors this way.
        if (null !== $request->query->get('error')) {
            $this->logger->info('OIDC login aborted by the identity provider.', [
                'error' => (string) $request->query->get('error'),
            ]);

            throw new CustomUserMessageAuthenticationException('login.oidc.error.provider');
        }

        $code = $request->query->get('code');
        if (!is_string($code) || '' === $code) {
            throw new CustomUserMessageAuthenticationException('login.oidc.error.token');
        }

        try {
            $tokens = $this->client->exchangeCode($code, $expected['code_verifier'] ?? '', $expected['redirect_uri'] ?? '');
            $claims = $this->idTokenValidator->validate($tokens['id_token'], $expected['nonce'] ?? '');
            $claims = $this->addUserInfoClaims($claims, $tokens);
        } catch (\Throwable $e) {
            // Configuration and provider problems are operator business; the
            // browser only ever sees a generic failure.
            $this->logger->error('OIDC login failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            throw new CustomUserMessageAuthenticationException('login.oidc.error.token');
        }

        $user = $this->userResolver->resolve($claims);

        $session->set(self::SESSION_AUTHENTICATED, true);
        // Only kept when we actually need it as an id_token_hint on logout.
        if ($this->config->isEndSessionEnabled() && isset($tokens['id_token'])) {
            $session->set(self::SESSION_ID_TOKEN, $tokens['id_token']);
        }

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), static fn (): object => $user)
        );
    }

    /**
     * Unlike the passkey flow (which answers JSON to a fetch call), this is a
     * browser redirect, so we send the user on to wherever they were headed.
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $targetPath = $this->getTargetPath($request->getSession(), $firewallName);

        return new RedirectResponse($targetPath ?? $this->urlGenerator->generate('dashboard.redirect'));
    }

    /**
     * Hand the failure to the login page, which already renders
     * SecurityRequestAttributes::AUTHENTICATION_ERROR in its alert box.
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->urlGenerator->generate('login'));
    }

    /**
     * Top up the ID token claims from the UserInfo endpoint when the claim we
     * match on is missing.
     *
     * Providers differ on what they put in the ID token: several (Authelia and
     * Okta among them) keep it minimal and serve e-mail and profile claims from
     * UserInfo only. The extra round trip therefore happens on demand, not on
     * every login.
     *
     * ID token claims win the merge — they are the ones whose signature we
     * checked against the login attempt.
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $tokens
     *
     * @return array<string, mixed>
     */
    private function addUserInfoClaims(array $claims, array $tokens): array
    {
        if (!$this->userResolver->needsUserInfo($claims)) {
            return $claims;
        }

        $accessToken = $tokens['access_token'] ?? null;
        if (!is_string($accessToken) || '' === $accessToken) {
            $this->logger->error('OIDC: claims are missing from the ID token and no access token was issued to query UserInfo with.');

            return $claims;
        }

        try {
            $userInfo = $this->client->fetchUserInfo($accessToken, (string) $claims['sub']);
        } catch (\Throwable $e) {
            // Fall through with what we have; the resolver produces the
            // user-facing "no matching account" message.
            $this->logger->error('OIDC: could not read the UserInfo endpoint: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return $claims;
        }

        return array_merge($userInfo, $claims);
    }

    /**
     * Pull the in-flight login values out of the session and remove them in the
     * same step, so each callback can only ever be used once.
     *
     * @return array{state: ?string, nonce: ?string, code_verifier: ?string, redirect_uri: ?string}
     */
    private function takeSessionValues(SessionInterface $session): array
    {
        $values = [];
        foreach ([
            'state' => self::SESSION_STATE,
            'nonce' => self::SESSION_NONCE,
            'code_verifier' => self::SESSION_CODE_VERIFIER,
            'redirect_uri' => self::SESSION_REDIRECT_URI,
        ] as $name => $key) {
            $value = $session->get($key);
            $session->remove($key);
            $values[$name] = is_string($value) ? $value : null;
        }

        return $values;
    }
}
