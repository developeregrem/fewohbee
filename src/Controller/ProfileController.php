<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\ApiScope;
use App\Entity\User;
use App\Form\ApiTokenType;
use App\Form\ProfilePersonalDataType;
use App\Repository\ApiTokenRepository;
use App\Repository\WebauthnCredentialRepository;
use App\Service\ApiTokenService;
use App\Service\Mcp\McpSettings;
use App\Service\UserService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[Route('/profile')]
final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly WebauthnCredentialRepository $credentialRepository,
        private readonly ApiTokenRepository $apiTokenRepository,
        private readonly McpSettings $mcpSettings,
    ) {
    }

    #[Route('/', name: 'profile', methods: [Request::METHOD_GET, Request::METHOD_POST])]
    public function __invoke(TokenStorageInterface $tokenStorage, Request $request, ManagerRegistry $doctrine, UserService $userService): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $passkeyEnabled = $this->isPasskeyEnabled();
        $form = $this->createForm(ProfilePersonalDataType::class, $user);
        $form->handleRequest($request);
        $plainPassword = (string) $form->get('password')->getData();
        if ($form->isSubmitted() && $form->isValid() && $userService->isPasswordValid($plainPassword, $user, $form)) {
            if (!empty($plainPassword)) {
                $user->setPassword($userService->hashPassword($plainPassword, $user));
            }
            $doctrine->getManager()->flush();
            $this->addFlash('success', 'profile.personal_data.updated');

            return $this->redirectToRoute('profile');
        }

        $credentials = [];
        if ($passkeyEnabled) {
            $credentials = $this->credentialRepository->findByUserHandle((string) $user->getId());
        }

        // Consume the one-time token flash here: the global flash loop in base.html.twig
        // would otherwise render (and thereby consume) it as a plain alert.
        $newApiToken = $request->getSession()->getFlashBag()->get('api_token_plain');
        $newApiToken = $newApiToken[0] ?? null;
        $newApiTokenEntity = null !== $newApiToken ? $this->apiTokenRepository->findOneByHash(ApiTokenService::hash($newApiToken)) : null;
        $mcpActive = $this->mcpSettings->isActive();

        return $this->render('Profile/index.html.twig', [
            'token' => $tokenStorage->getToken(),
            'credentials' => $credentials,
            'personalDataForm' => $form->createView(),
            'apiTokens' => $this->apiTokenRepository->findByUser($user),
            'newApiToken' => $newApiToken,
            // AI tokens get MCP instructions instead of the REST/calendar usage hint.
            'newApiTokenIsMcp' => $newApiTokenEntity?->hasScope(ApiScope::MCP_ACCESS) ?? false,
            'mcpEndpointUrl' => $mcpActive ? $this->generateUrl('_mcp_endpoint_default', [], UrlGeneratorInterface::ABSOLUTE_URL) : null,
            'apiTokenForm' => $this->createForm(ApiTokenType::class, null, [
                'action' => $this->generateUrl('profile.apitokens.create'),
            ])->createView(),
        ]);
    }

    #[Route('/passkey/delete/{id}', name: 'profile_delete_credential', methods: ['GET', 'DELETE'], requirements: ['id' => '.+'])]
    public function deleteCredential(Request $request, ManagerRegistry $doctrine, string $id): Response
    {
        if (!$this->isPasskeyEnabled()) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('delete'.$id, $request->request->get('_token'))) {
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw new AccessDeniedHttpException();
            }

            $credentials = $this->credentialRepository->findByUserHandle((string) $user->getId());
            foreach ($credentials as $credential) {
                if ($credential->getId() === $id) {
                    $doctrine->getManager()->remove($credential);
                    $doctrine->getManager()->flush();
                    break;
                }
            }

            $this->addFlash('success', 'profile.passkeys.delete');
        } else {
            $this->addFlash('warning', 'flash.invalidtoken');
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function isPasskeyEnabled(): bool
    {
        return (bool) $this->getParameter('passkey_enabled');
    }
}
