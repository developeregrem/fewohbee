<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use App\Entity\User;
use App\Entity\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;

use Symfony\Component\HttpFoundation\Request;

class UserService
{

    private $em = null;
    private $app;
    private $encoder;

    public function __construct(UserPasswordEncoderInterface $encoder, EntityManagerInterface $em)
    {
        $this->em = $em;
	$this->encoder = $encoder;
    }

    public function getUserFromForm(Request $request, $id = 'new')
    {

        $user = null;

        if ($id === 'new') {
            $user = new User();
            $user->setUsername($request->get("username-" . $id));
        } else {
            $user = $this->em->getRepository(User::class)->find($id);
        }

        $formPassword = $request->get("password-" . $id);
        if (!empty($formPassword) && $this->checkPassword($formPassword)) {
            $encoded = $this->encoder->encodePassword($user, $request->get("password-" . $id));
            $user->setPassword($encoded);
        }

        $user->setFirstname($request->get("firstname-" . $id));
        $user->setLastname($request->get("lastname-" . $id));
        $user->setEmail($request->get("email-" . $id));

        if ($request->get("active-" . $id) != null) {
            $user->setActive(true);
        } else {
            $user->setActive(false);
        }

        $role = $this->em->getRepository(Role::class)->find($request->get("role-" . $id));
        $user->setRole($role);

        return $user;
    }
    
    public function checkPassword($password) {
        if (!empty($password)) {
            if(strlen($password) < 8) {
                return false;
            }
        }
        return true;
    }

    public function deleteUser($id)
    {
        $user = $this->em->getRepository(User::class)->find($id);

        $this->em->remove($user);
        $this->em->flush();

        return true;
    }
    
    public function isUsernameAvailable(string $username) {
        return $this->em->getRepository(User::class)->isUsernameAvailable($username);
    }
}
