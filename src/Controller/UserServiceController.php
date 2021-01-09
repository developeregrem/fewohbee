<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use App\Entity\User;
use App\Entity\Role;
use App\Service\UserService;
use App\Service\CSRFProtectionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class UserServiceController extends AbstractController
{
    public function __construct()
    {

    }

    public function indexAction()
    {
	$em = $this->getDoctrine()->getManager();
        $users = $em->getRepository(User::class)->findAll();

        return $this->render('Users/index.html.twig', array(
            "users" => $users
        ));
    }

    public function getUserAction(CSRFProtectionService $csrf, $id)
    {
        $em = $this->getDoctrine()->getManager();
		$user = $em->getRepository(User::class)->find($id);
        $roles = $em->getRepository(Role::class)->findAll();

        return $this->render('Users/user_form_edit.html.twig', array(
            'user' => $user,
            'roles' => $roles,
            'token' => $csrf->getCSRFTokenForForm()
        ));
    }

    public function newUserAction(CSRFProtectionService $csrf)
    {
        $em = $this->getDoctrine()->getManager();
        $roles = $em->getRepository(Role::class)->findAll();
        $user = new User();
        $user->setId('new');

        return $this->render('Users/user_form_create.html.twig', array(
            'roles' => $roles,
            'user' => $user,
            'token' => $csrf->getCSRFTokenForForm()
        ));
    }

    public function createUserAction(Request $request, UserService $userService, CSRFProtectionService $csrf)
    {
        $em = $this->getDoctrine()->getManager();
		$error = false;
        if (($csrf->validateCSRFToken($request))) {
            $userem = $em->getRepository(User::class);
            /* @var $user \Pensionsverwaltung\Database\Entity\User */
            $user = $userService->getUserFromForm($request, "new");

            // check username
            if (!$userService->isUsernameAvailable($user->getUsername())) {
                $this->addFlash('warning', 'user.flash.username.na');
                $error = true;
            } else if (strlen($user->getUsername()) == 0 || strlen($user->getFirstname()) == 0 || strlen($user->getLastname()) == 0
                || strlen($user->getEmail()) == 0 || strlen($user->getPassword()) == 0) {
                // check for mandatory fields
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else if(!$userService->checkPassword($request->get("password-new"))) {
                $error = true;
                $this->addFlash('warning', 'user.password.error');
            } else {
                $em->persist($user);
                $em->flush();

                // add succes message
                $this->addFlash('success', 'user.flash.create.success');
            }
        }

        return $this->render('feedback.html.twig', array(
            "error" => $error
        ));
    }

    public function editUserAction(Request $request, $id, UserService $userService, CSRFProtectionService $csrf)
    {        
        $error = false;
        if (($csrf->validateCSRFToken($request))) {
            $user = $userService->getUserFromForm($request, $id);
            $em = $this->getDoctrine()->getManager();
            
            if(!$userService->checkPassword($request->get("password-".$id))) {
                $error = true;
                $this->addFlash('warning', 'user.password.error');
                // check for mandatory fields
            } else if (strlen($user->getUsername()) == 0 || strlen($user->getFirstname()) == 0 || strlen($user->getLastname()) == 0
                || strlen($user->getEmail()) == 0) {        
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
                // stop auto commit of doctrine with invalid field values
                // this results in an error when the current user edit themself
                //$em->clear(User::class);
            } else {
                $em->persist($user);
                $em->flush();

                // add succes message
                $this->addFlash('success', 'user.flash.edit.success');
            }
        }

        return $this->render('feedback.html.twig', array(
            "error" => $error
        ));
    }

    public function deleteUserAction(Request $request, $id, UserService $userService, CSRFProtectionService $csrf)
    {
        if ($request->getMethod() == 'POST') {
            if (($csrf->validateCSRFToken($request, true))) {
                $user = $userService->deleteUser($id);
                $this->addFlash('success', 'user.flash.delete.success');
            }
            return new Response('', Response::HTTP_NO_CONTENT);
        } else {
            // initial get load (ask for deleting)
            return $this->render('common/form_delete_entry.html.twig', array(
                "id" => $id,
                'token' => $csrf->getCSRFTokenForForm()
            ));
        }

    }
}