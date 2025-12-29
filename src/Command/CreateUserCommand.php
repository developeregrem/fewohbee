<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Role;
use App\Entity\User;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:create-user',
    description: 'Create a new user via command line.',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly UserService $us
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $helper = $this->getHelper('question');

        $io->title('This process will ask you questions in order to create a new user.');

        $username = $io->ask('Username', null, function ($input) {
            if (empty($input)) {
                throw new \RuntimeException('Username must not be empty!');
            }
            if (!$this->us->isUsernameAvailable($input)) {
                throw new \RuntimeException('Username already exists!');
            }

            return $input;
        });
        $password = $io->ask('Password (min 10 characters)', null, function ($input) {
            $this->us->isPasswordValid($input, new User());

            return $input;
        });
        $firstName = $io->ask('Firstname', null, function ($input) {
            if (empty($input)) {
                throw new \RuntimeException('Firstname must not be empty!');
            }

            return $input;
        });
        $lastName = $io->ask('Lastname', null, function ($input) {
            if (empty($input)) {
                throw new \RuntimeException('Lastname must not be empty!');
            }

            return $input;
        });
        $email = $io->ask('E-Mail', null, function ($input) {
            $emailConstraint = new Assert\Email();
            $errors = $this->validator->validate($input, $emailConstraint);
            if (empty($input) || count($errors) > 0) {
                throw new \RuntimeException('You must insert a valid mail address!');
            }

            return $input;
        });

        $availableRoles = $this->em->getRepository(Role::class)->findAll();
        if (0 === \count($availableRoles)) {
            throw new \RuntimeException('No roles have been configured yet. Please run the first-run command first.');
        }
        $choices = [];
        foreach ($availableRoles as $roleEntity) {
            $choices[$roleEntity->getRole()] = $roleEntity;
        }
        $choiceKeys = array_keys($choices);
        $default = '';
        if (\in_array('ROLE_RESERVATIONS', $choiceKeys, true)) {
            $default = 'ROLE_RESERVATIONS';
        } elseif (!empty($choiceKeys)) {
            $default = $choiceKeys[0];
        }
        $question = new ChoiceQuestion(
            'Please select the user roles (comma separated)',
            $choiceKeys,
            $default
        );
        $question->setMultiselect(true);
        $question->setErrorMessage('Role %s is invalid.');
        $selectedRoles = (array) $helper->ask($input, $output, $question);
        if (0 === \count($selectedRoles)) {
            throw new \RuntimeException('At least one role must be selected.');
        }
        $user = new User();
        $user->setEmail($email);
        $user->setFirstname($firstName);
        $user->setLastname($lastName);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $user->setUsername($username);
        foreach ($selectedRoles as $selectedRole) {
            if (isset($choices[$selectedRole])) {
                $user->addRole($choices[$selectedRole]);
            }
        }
        $user->setActive(true);
        $this->em->persist($user);

        $this->em->flush();

        $io->success('User created!');

        return Command::SUCCESS;
    }
}
