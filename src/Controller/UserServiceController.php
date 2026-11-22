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
use App\Form\UserEditType;
use App\Form\UserType;
use App\Service\UserService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settings/users')]
class UserServiceController extends AbstractController
{
    #[Route('/', name: 'users.overview', methods: ['GET'])]
    public function indexAction(ManagerRegistry $doctrine): Response
    {
        $em = $doctrine->getManager();
        $users = $em->getRepository(User::class)->findAll();

        return $this->render('Users/index.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/new', name: 'users.new.user', methods: ['GET', 'POST'])]
    public function new(ManagerRegistry $doctrine, Request $request, UserService $us): Response
    {
        $user = new User();
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);
        $pw = $form->get('password')->getData();

        if ($form->isSubmitted() && $form->isValid() && $us->isPasswordValid($pw, $user, $form)) {
            $user->setPassword($us->hashPassword($pw, $user));

            $entityManager = $doctrine->getManager();
            $entityManager->persist($user);
            $entityManager->flush();

            // add success message
            $this->addFlash('success', 'user.flash.create.success');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('Users/new.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'users.edit.user', methods: ['GET', 'POST'])]
    public function edit(ManagerRegistry $doctrine, Request $request, UserService $us, User $user): Response
    {
        $oldUsername = $user->getUsername();
        $oldPw = $user->getPassword();
        $form = $this->createForm(UserEditType::class, $user, ['old_username' => $oldUsername]);
        $form->handleRequest($request);
        $pw = $form->get('password')->getData();

        if ($form->isSubmitted() && $form->isValid() && $us->isPasswordValid($pw, $user, $form)) {
            if (!empty($pw)) {
                $user->setPassword($us->hashPassword($pw, $user));
            } else {
                $user->setPassword($oldPw);
            }
            $doctrine->getManager()->flush();

            // add success message
            $this->addFlash('success', 'user.flash.edit.success');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('Users/edit.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Switches a user on or off from the list (ActiveToggle). Not one's own account: switched off
     * it could not log in again to switch itself back on. Answers the list's fetch with 204, or
     * 409 with a flash message to show; a plain form post with the list.
     */
    #[Route('/{id}/toggle', name: 'users.toggle.user', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(ManagerRegistry $doctrine, Request $request, User $user): Response
    {
        // Refused before the token is checked: it changes nothing, and the list offers no switch
        // (so no token) for one's own account
        $current = $this->getUser();
        if ($current instanceof User && $current->getId() === $user->getId()) {
            $this->addFlash('warning', 'user.flash.toggle.self');

            return $request->isXmlHttpRequest()
                ? new Response(status: Response::HTTP_CONFLICT)
                : $this->redirectToRoute('users.overview');
        }
        if (!$this->isCsrfTokenValid('user-toggle-'.$user->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $user->setActive(!$user->getActive());
        $doctrine->getManager()->flush();

        return $request->isXmlHttpRequest()
            ? new Response(status: Response::HTTP_NO_CONTENT)
            : $this->redirectToRoute('users.overview');
    }

    /** @param int|string $id Route ID, including numeric strings */
    #[Route('/{id}/delete', name: 'users.delete.user', methods: ['DELETE'])]
    public function deleteUserAction(Request $request, $id, UserService $us, User $user): Response
    {
        if ($this->isCsrfTokenValid('delete'.$user->getId(), $request->request->get('_token'))) {
            $status = $us->deleteUser($user);

            $this->addFlash('success', 'user.flash.delete.success');
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Remove the identity provider binding from an account so it can be linked
     * to a different subject on the next single sign-on.
     *
     * Needed when staff change (a new person inherits the mailbox), when the
     * identity provider is rebuilt and issues new subjects, or when a first
     * link went to the wrong account. The user keeps existing; only the binding
     * goes away.
     *
     * Reuses the shared delete popover, whose CSRF token id is 'delete' ~ id.
     */
    #[Route('/{id}/unlink-sso', name: 'users.unlink.sso', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function unlinkSsoAction(Request $request, User $user, ManagerRegistry $doctrine): Response
    {
        if ($this->isCsrfTokenValid('delete'.$user->getId(), (string) $request->request->get('_token'))) {
            $user->unlinkOidcIdentity();
            $doctrine->getManager()->flush();

            $this->addFlash('success', 'user.oidc.flash.unlinked');
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
