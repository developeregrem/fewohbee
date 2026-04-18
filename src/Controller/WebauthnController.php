<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\WebauthnCredentialRepository;
use App\Service\WebauthnService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Handles the server-side WebAuthn ceremony endpoints for passkey
 * registration (profile) and authentication (login).
 *
 * Each ceremony consists of two steps:
 *  1. OPTIONS – generate a challenge and return PublicKeyCredential*Options as JSON
 *  2. VERIFY  – receive the authenticator response and validate it
 *
 * The authentication VERIFY endpoint (/login/webauthn) is intercepted by
 * WebauthnAuthenticator and never reaches the controller action.
 */
final class WebauthnController extends AbstractController
{
    public function __construct(
        private readonly WebauthnService $webauthnService,
        private readonly WebauthnCredentialRepository $credentialRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * Generate PublicKeyCredentialCreationOptions for the browser and store
     * them in the session so they can be verified in the next step.
     */
    #[Route('/profile/security/devices/add/options', name: 'webauthn_registration_options', methods: ['POST'])]
    public function registrationOptions(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $userEntity = new PublicKeyCredentialUserEntity(
            name: $user->getUsername(),
            id: (string) $user->getId(),
            displayName: $user->getUserIdentifier(),
        );

        $options = $this->webauthnService->generateRegistrationOptions($userEntity);

        $json = $this->webauthnService->serializeOptions($options);
        $request->getSession()->set('webauthn_registration_options', $json);

        return new JsonResponse(json_decode($json, true));
    }

    /**
     * Verify the attestation response from the browser, create a new
     * WebauthnCredential entity with auto-detected device label and persist it.
     */
    #[Route('/profile/security/devices/add', name: 'webauthn_registration_verify', methods: ['POST'])]
    public function registrationVerify(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $session = $request->getSession();
        $optionsJson = $session->get('webauthn_registration_options');
        $session->remove('webauthn_registration_options');

        if (null === $optionsJson) {
            return new JsonResponse(['error' => 'No registration in progress'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $credential = $this->webauthnService->deserializeCredential($request->getContent());
            $options = $this->webauthnService->deserializeCreationOptions($optionsJson);

            if (!$credential->response instanceof AuthenticatorAttestationResponse) {
                return new JsonResponse(['error' => 'Invalid response type'], Response::HTTP_BAD_REQUEST);
            }

            $credentialRecord = $this->webauthnService->verifyRegistration(
                $credential->response,
                $options,
                $request->getHost(),
            );

            $entity = \App\Entity\WebauthnCredential::fromCredentialRecord($credentialRecord);
            $this->credentialRepository->saveWithDeviceDetection($entity);

            return new JsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * This route is intercepted by WebauthnAuthenticator.
     * The controller action is never reached — it exists only for URL generation.
     */
    #[Route('/login/webauthn', name: 'webauthn_authentication_verify', methods: ['POST'])]
    public function authenticationVerify(): void
    {
    }

    /**
     * Generate PublicKeyCredentialRequestOptions for the browser.
     * If the request body contains a username, the options restrict
     * allowed credentials to that user. Otherwise any discoverable
     * passkey may be used (conditional UI / autofill).
     */
    #[Route('/login/webauthn/options', name: 'webauthn_authentication_options', methods: ['POST'])]
    public function authenticationOptions(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $username = $data['username'] ?? null;

        $userHandle = null;
        if ($username) {
            $user = $this->userRepository->findOneBy(['username' => $username]);
            if ($user instanceof User) {
                $userHandle = (string) $user->getId();
            }
        }

        $options = $this->webauthnService->generateAuthenticationOptions($userHandle);

        $json = $this->webauthnService->serializeOptions($options);
        $request->getSession()->set('webauthn_authentication_options', $json);

        return new JsonResponse(json_decode($json, true));
    }
}
