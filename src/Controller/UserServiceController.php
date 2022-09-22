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

use App\Entity\Role;
use App\Entity\User;
use App\Form\UserEditType;
use App\Form\UserType;
use App\Service\CSRFProtectionService;
use App\Service\UserService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/users')]
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

    #[Route('/{id}/get', name: 'users.get.user', defaults: ['id' => '0'], methods: ['GET'])]
    public function getUserAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, $id): Response
    {
        $em = $doctrine->getManager();
        $user = $em->getRepository(User::class)->find($id);
        $roles = $em->getRepository(Role::class)->findAll();

        return $this->render('Users/user_form_edit.html.twig', [
            'user' => $user,
            'roles' => $roles,
            'token' => $csrf->getCSRFTokenForForm(),
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

    #[Route('/{id}/delete', name: 'users.delete.user', methods: ['GET', 'POST'])]
    public function deleteUserAction(Request $request, $id, UserService $userService, CSRFProtectionService $csrf): Response
    {
        if ('POST' == $request->getMethod()) {
            if ($csrf->validateCSRFToken($request, true)) {
                $user = $userService->deleteUser($id);
                $this->addFlash('success', 'user.flash.delete.success');
            }

            return new Response('', Response::HTTP_NO_CONTENT);
        } else {
            // initial get load (ask for deleting)
            return $this->render('common/form_delete_entry.html.twig', [
                'id' => $id,
                'token' => $csrf->getCSRFTokenForForm(),
            ]);
        }
    }
}
