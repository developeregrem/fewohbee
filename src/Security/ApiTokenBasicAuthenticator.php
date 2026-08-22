<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\ApiTokenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Basic-auth bridge for clients that cannot send custom headers (e.g. calendar apps):
 * username + API token as password. The real login password is never accepted here —
 * supports() requires the token prefix and validation only ever consults api_tokens.
 */
class ApiTokenBasicAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly ApiTokenService $apiTokenService,
        private readonly ApiTokenContext $apiTokenContext,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        $password = $request->getPassword();

        return null !== $password && str_starts_with($password, ApiTokenService::TOKEN_PREFIX);
    }

    public function authenticate(Request $request): Passport
    {
        $apiToken = $this->apiTokenService->validate((string) $request->getPassword());
        $user = $apiToken->getUser();

        if (0 !== strcasecmp((string) $request->getUser(), $user->getUsername())) {
            throw new BadCredentialsException('Invalid API token.');
        }

        $this->apiTokenContext->setToken($apiToken);

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(
            ['error' => ['code' => Response::HTTP_UNAUTHORIZED, 'message' => 'Invalid credentials.']],
            Response::HTTP_UNAUTHORIZED
        );
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $response = new JsonResponse(
            ['error' => ['code' => Response::HTTP_UNAUTHORIZED, 'message' => 'Authentication required.']],
            Response::HTTP_UNAUTHORIZED
        );
        // Bearer for API clients, Basic so that calendar clients (Thunderbird & co.) prompt for credentials.
        $response->headers->set('WWW-Authenticate', 'Bearer realm="fewohbee-api", Basic realm="fewohbee-api"');

        return $response;
    }
}
