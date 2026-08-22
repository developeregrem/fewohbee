<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\User;
use App\Form\ApiTokenType;
use App\Repository\ApiTokenRepository;
use App\Service\ApiTokenService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/profile/api-tokens')]
final class ProfileApiTokenController extends AbstractController
{
    public function __construct(
        private readonly ApiTokenService $apiTokenService,
        private readonly ApiTokenRepository $apiTokenRepository,
    ) {
    }

    #[Route('/', name: 'profile.apitokens.create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $form = $this->createForm(ApiTokenType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $expiresIn = (string) ($data['expiresIn'] ?? '');
            $expiresAt = '' !== $expiresIn ? (new \DateTimeImmutable())->modify($expiresIn) : null;

            $result = $this->apiTokenService->createToken($user, (string) $data['name'], $data['scopes'], $expiresAt);

            // Shown exactly once on the next page load; never stored in plain text.
            $this->addFlash('api_token_plain', $result->plainToken);
            $this->addFlash('success', 'profile.apitokens.created');
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('warning', $error->getMessage());
            }
        }

        return $this->redirectToRoute('profile');
    }

    #[Route('/delete/{id}', name: 'profile.apitokens.delete', methods: ['GET', 'DELETE'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, ManagerRegistry $doctrine, int $id): Response
    {
        if ($this->isCsrfTokenValid('delete'.$id, $request->request->get('_token'))) {
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw new AccessDeniedHttpException();
            }

            $token = $this->apiTokenRepository->find($id);
            if (null !== $token && $token->getUser() === $user) {
                $doctrine->getManager()->remove($token);
                $doctrine->getManager()->flush();
                $this->addFlash('success', 'profile.apitokens.deleted');
            }
        } else {
            $this->addFlash('warning', 'flash.invalidtoken');
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
