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

namespace App\Command;

use App\DataFixtures\ReservationFixtures;
use App\DataFixtures\SettingsFixtures;
use App\DataFixtures\TemplatesFixtures;
use App\Workflow\WorkflowSeeder;
use App\Entity\Customer;
use App\Entity\Role;
use App\Entity\Subsidiary;
use App\Entity\TemplateType;
use App\Entity\User;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:first-run',
    description: 'This command will prepare the app for the first use.',
)]
class FirstRunCommand extends Command
{
    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly UserService $us,
        private readonly TemplatesFixtures $templatesFixtures,
        private readonly SettingsFixtures $settingsFixtures,
        private readonly ReservationFixtures $reservationFixtures,
        private readonly WorkflowSeeder $workflowSeeder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Admin username that should be created.')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password that must meet the regular password constraints.')
            ->addOption('first-name', null, InputOption::VALUE_REQUIRED, 'Firstname for the initial admin.')
            ->addOption('last-name', null, InputOption::VALUE_REQUIRED, 'Lastname for the initial admin.')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email address for the admin user.')
            ->addOption('accommodation-name', null, InputOption::VALUE_REQUIRED, 'Name of the accommodation that should be created.')
            ->addOption('load-sample-data', null, InputOption::VALUE_NONE, 'Load sample data (rooms, prices, reservations, invoices).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('This process will ask you questions in order to prepare the app for its first use.');

        $users = $this->em->getRepository(User::class)->findAll();

        if (count($users) > 0) {
            $io->error('App already prepared!');

            return Command::FAILURE;
        }

        $username = $this->resolveValue(
            $input,
            $io,
            'username',
            'Username',
            fn ($value) => $this->assertNotEmpty($value, 'Username must not be empty!')
        );
        $password = $this->resolveValue(
            $input,
            $io,
            'password',
            'Password (min 10 characters)',
            function ($value) {
                $this->us->isPasswordValid($value, new User());

                return $value;
            }
        );
        $firstName = $this->resolveValue(
            $input,
            $io,
            'first-name',
            'Firstname',
            fn ($value) => $this->assertNotEmpty($value, 'Field cannot be empty!')
        );
        $lastName = $this->resolveValue(
            $input,
            $io,
            'last-name',
            'Lastname',
            fn ($value) => $this->assertNotEmpty($value, 'Field cannot be empty!')
        );
        $email = $this->resolveValue(
            $input,
            $io,
            'email',
            'E-Mail',
            function ($value) {
                $emailConstraint = new Assert\Email();
                $errors = $this->validator->validate($value, $emailConstraint);
                if (empty($value) || count($errors) > 0) {
                    throw new \RuntimeException('You must insert a valid mail address!');
                }

                return $value;
            }
        );

        $rolesToCreate = [
            'ROLE_ADMIN' => 'Admin',
            // other roles are created via migration
        ];
        $createdRoles = [];
        foreach ($rolesToCreate as $roleCode => $roleName) {
            $role = new Role();
            $role->setName($roleName);
            $role->setRole($roleCode);
            $this->em->persist($role);
            $createdRoles[$roleCode] = $role;
        }

        $user = new User();
        $user->setActive(true);
        $user->setEmail($email);
        $user->setFirstname($firstName);
        $user->setLastname($lastName);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $user->setUsername($username);
        if (isset($createdRoles['ROLE_ADMIN'])) {
            $user->addRole($createdRoles['ROLE_ADMIN']);
        }
        $this->em->persist($user);
        $io->note('User and Roles created.');

        $this->createTemplateTypes();
        $io->note('Templates prepared.');

        $name = $this->resolveValue(
            $input,
            $io,
            'accommodation-name',
            'What is the name of your accommodation',
            fn ($value) => $this->assertNotEmpty($value, 'Field must not be empty!')
        );

        $sub = new Subsidiary();
        $sub->setName($name);
        $sub->setDescription('');
        $this->em->persist($sub);
        $io->note('Accommodation prepared.');

        $this->createDummyCustomer();
        $io->note('Customers prepared.');

        $this->em->flush();

        $this->templatesFixtures->load($this->em);
        $io->note('Base templates loaded.');

        $loadSampleData = $input->getOption('load-sample-data')
            || ($input->isInteractive() && $io->confirm('Load sample data (rooms, prices, reservations, invoices)?', false));
        if ($loadSampleData) {
            $this->settingsFixtures->load($this->em);
            $this->reservationFixtures->load($this->em);
            $io->note('Sample data loaded.');

            $this->workflowSeeder->seedExampleWorkflows();
            $io->note('Example workflows created.');
        }

        $io->success('All done! You can now navigate to the app and login with the provided username and password.');

        return Command::SUCCESS;
    }

    private function createTemplateTypes(): void
    {
        $definitions = [
            'TEMPLATE_GENERAL_EMAIL' => 'fa-envelope',
            'TEMPLATE_RESERVATION_EMAIL' => 'fa-envelope',
            'TEMPLATE_INVOICE_EMAIL' => 'fa-envelope',
            'TEMPLATE_FILE_PDF' => 'fa-file-pdf',
            'TEMPLATE_INVOICE_PDF' => 'fa-file-pdf',
            'TEMPLATE_RESERVATION_PDF' => 'fa-file-pdf',
            'TEMPLATE_CASHJOURNAL_PDF' => 'fa-file-pdf',
            'TEMPLATE_GDPR_PDF' => 'fa-file-pdf',
            'TEMPLATE_OPERATIONS_PDF' => 'fa-file-pdf',
            'TEMPLATE_REGISTRATION_PDF' => 'fa-file-pdf',
        ];

        foreach ($definitions as $name => $icon) {
            $templateType = $this->em->getRepository(TemplateType::class)->findOneBy(['name' => $name]);
            if (!$templateType instanceof TemplateType) {
                $templateType = new TemplateType();
                $templateType->setName($name);
                $this->em->persist($templateType);
            }

            $templateType->setIcon($icon);
        }
    }

    private function createDummyCustomer(): void
    {
        $c = new Customer();
        $c->setFirstname('Anonym');
        $c->setLastname('Anonym');
        $c->setSalutation('Herr');
        $this->em->persist($c);
    }

    private function resolveValue(InputInterface $input, SymfonyStyle $io, string $optionName, string $question, callable $validator): string
    {
        $value = $input->getOption($optionName);
        if (null !== $value) {
            return $validator($value);
        }

        if (!$input->isInteractive()) {
            throw new \RuntimeException(sprintf('Option "--%s" is required when running without interaction.', $optionName));
        }

        return $io->ask($question, null, $validator);
    }

    private function assertNotEmpty(?string $value, string $message): string
    {
        if (null === $value || '' === trim($value)) {
            throw new \RuntimeException($message);
        }

        return $value;
    }
}
