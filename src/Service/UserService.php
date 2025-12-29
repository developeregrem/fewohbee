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

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class UserService
{
    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator
    ) {
    }

    public function hashPassword(string $password, User $user): string
    {
        return $this->hasher->hashPassword($user, $password);
    }

    public function isPasswordValid(string $password, User $user, ?FormInterface $form = null, $pwField = 'password'): bool
    {
        $success = true;
        // check password during create and when it's not empty during edit
        if (null === $user->getId() || !empty($password)) {
            $constraints = [
                new Length(
                    min: 10,
                    minMessage: 'form.password.min',
                    // max length allowed by Symfony for security reasons
                    max: 4096,
                ),
                new NotCompromisedPassword(skipOnError: true),
            ];

            $violations = $this->validator->validate($password, $constraints);
            if ($violations->count() > 0) {
                foreach ($violations as $violation) {
                    if ($violation instanceof ConstraintViolation) {
                        $success = false;
                        $message = $violation->getMessage();
                        $message = \is_string($message) ? $message : '';
                        if (null !== $form) {
                            $form->get($pwField)->addError(new FormError($message));
                        } else {
                            throw new \RuntimeException('❌ '.$message.' ❌');
                        }
                    }
                }
            }
        }

        return $success;
    }

    public function deleteUser(User $user): bool
    {
        $this->em->remove($user);
        $this->em->flush();

        return true;
    }

    public function isUsernameAvailable(string $username): bool
    {
        return $this->em->getRepository(User::class)->isUsernameAvailable($username);
    }
}
