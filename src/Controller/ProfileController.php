<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\ProfilePersonalDataType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Webauthn\Bundle\Repository\PublicKeyCredentialUserEntityRepositoryInterface;
use Webauthn\PublicKeyCredentialSource;
use App\Repository\WebauthnCredentialRepository;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use App\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use App\Service\UserService;

#[Route('/profile')]
final class ProfileController extends AbstractController
{

    public function __construct(
        private readonly PublicKeyCredentialUserEntityRepositoryInterface $keyCredentialUserEntityRepository,
        private readonly WebauthnCredentialRepository $keyCredentialSourceRepository,
        private readonly CsrfTokenManagerInterface $csrfTokenManager
    ) {
    }
    
    #[Route('/', name: 'profile', methods: [Request::METHOD_GET, Request::METHOD_POST])]
    public function __invoke(TokenStorageInterface $tokenStorage, Request $request, ManagerRegistry $doctrine, UserService $userService): Response
    {
        $user = $this->getUser();
        if (! $user instanceof User) {
            throw new AccessDeniedHttpException();
        }

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

        $userEntity = $this->keyCredentialUserEntityRepository->findOneByUserHandle((string)$user->getId());
        if ($userEntity === null) {
            throw new AccessDeniedHttpException();
        }

        $credentials = $this->keyCredentialSourceRepository->findAllForUserEntity($userEntity);
        $credentials = array_map(static function (PublicKeyCredentialSource $source) {

            return [
                'publicKeyCredentialId' => $source->publicKeyCredentialId,
                'type' => $source->type,
                'transports' => $source->transports,
                'attestationType' => $source->attestationType,
                //'trustPath' => $this->trustPath->jsonSerialize(),
                'aaguid' => $source->aaguid->toRfc4122(),
                'credentialPublicKey' => $source->credentialPublicKey,
                //'userHandle' => Base64UrlSafe::encodeUnpadded($this->userHandle),
                'counter' => $source->counter,
                'clientLabel' => $source instanceof \App\Entity\WebauthnCredential ? $source->getClientLabel() : null,
                'userAgent' => $source instanceof \App\Entity\WebauthnCredential ? $source->getUserAgent() : null,
                'createdAt' => $source instanceof \App\Entity\WebauthnCredential ? $source->getCreatedAt() : null,
                //'otherUI' => $this->otherUI,
            ];

        }, $credentials);
        return $this->render('Profile/index.html.twig', [
            'token' => $tokenStorage->getToken(),
            'credentials' => $credentials,
            'personalDataForm' => $form->createView(),
        ]);
    }

    #[Route('/passkey/delete/{id}', name: 'profile_delete_credential', methods: ['GET', 'DELETE'], requirements: ['id' => '.+'])]
    public function deleteCredential(Request $request, ManagerRegistry $doctrine, string $id): Response
    {
        if ('GET' === $request->getMethod()) {
            // initial get load (ask for deleting)
            return $this->render('common/form_delete_ask.html.twig', [
                'id' => bin2hex($id),
            ]);
        } elseif ($this->isCsrfTokenValid('delete'.$id, $request->request->get('_token'))) {
            $user = $this->getUser();
            if (! $user instanceof User) {
                throw new AccessDeniedHttpException();
            }

            $userEntity = $this->keyCredentialUserEntityRepository->findOneByUserHandle((string)$user->getId());
            if ($userEntity === null) {
                throw new AccessDeniedHttpException();
            }

            // $id is base64 encoded, decode it
            $credentialId = base64_decode(hex2bin($id));
            //var_dump($credentialId);
            if ($credentialId === false) {
                throw new AccessDeniedHttpException('Invalid credential id');
            }

            $credentials = $this->keyCredentialSourceRepository->findAllForUserEntity($userEntity);
            foreach ($credentials as $credential) {
                if ($credential->publicKeyCredentialId === $credentialId) {
                    $doctrine->getManager()->remove($credential);
                    $doctrine->getManager()->flush();
                    break;
                }
            }

            $this->addFlash('success', 'profile.passkeys.delete');
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
