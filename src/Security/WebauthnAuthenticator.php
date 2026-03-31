<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\UserRepository;
use App\Repository\WebauthnCredentialRepository;
use App\Service\WebauthnService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Webauthn\AuthenticatorAssertionResponse;

/**
 * Symfony Security authenticator for passkey-based login.
 *
 * Intercepts POST requests to /login/webauthn, deserializes the
 * authenticator assertion response, verifies it against the stored
 * credential and challenge, and issues a SelfValidatingPassport so
 * Symfony creates an authenticated session.
 *
 * Works alongside the standard form_login authenticator — the
 * entry_point stays on form_login so unauthenticated users are
 * redirected to the login page as usual.
 */
final class WebauthnAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly WebauthnService $webauthnService,
        private readonly WebauthnCredentialRepository $credentialRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * Only handle POST to the passkey verification endpoint.
     */
    public function supports(Request $request): ?bool
    {
        return $request->isMethod('POST')
            && '/login/webauthn' === $request->getPathInfo();
    }

    /**
     * Verify the passkey assertion: deserialize credential, look up the
     * stored record, validate the cryptographic signature and counter,
     * then persist the updated counter and return a passport.
     */
    public function authenticate(Request $request): SelfValidatingPassport
    {
        $session = $request->getSession();
        $optionsJson = $session->get('webauthn_authentication_options');
        $session->remove('webauthn_authentication_options');

        if (null === $optionsJson) {
            throw new AuthenticationException('No authentication challenge in progress.');
        }

        try {
            $credential = $this->webauthnService->deserializeCredential($request->getContent());

            if (!$credential->response instanceof AuthenticatorAssertionResponse) {
                throw new AuthenticationException('Invalid response type.');
            }

            $entity = $this->credentialRepository->findOneByCredentialId($credential->rawId);
            if (null === $entity) {
                throw new AuthenticationException('Unknown credential.');
            }

            $options = $this->webauthnService->deserializeRequestOptions($optionsJson);
            $credentialRecord = $entity->toCredentialRecord();

            $updatedRecord = $this->webauthnService->verifyAuthentication(
                $credentialRecord,
                $credential->response,
                $options,
                $request->getHost(),
                $entity->getUserHandle(),
            );

            $entity->updateFromCredentialRecord($updatedRecord);
            $this->credentialRepository->save($entity);

            return new SelfValidatingPassport(
                new UserBadge($entity->getUserHandle(), function (string $userIdentifier) {
                    $user = $this->userRepository->find((int) $userIdentifier);
                    if (null === $user) {
                        throw new UserNotFoundException();
                    }

                    return $user;
                })
            );
        } catch (AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AuthenticationException('Passkey authentication failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Return a JSON success response — the frontend Stimulus controller
     * handles the actual redirect to the dashboard.
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new JsonResponse(['success' => true]);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(
            ['error' => $exception->getMessageKey()],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
