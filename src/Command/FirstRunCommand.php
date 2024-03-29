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

        $io->title('This process will ask you questions in order to prepare the app for its first use.');

        $users = $this->em->getRepository(User::class)->findAll();

        if (count($users) > 0) {
            $io->error('App already prepared!');

            return 1;
        }

        $username = $io->ask('Username', null, function ($input) {
            if (empty($input)) {
                throw new \RuntimeException('Username must not be empty!');
            }

            return $input;
        });
        $password = $io->ask('Password (min 10 characters)', null, function ($input) {
            $this->us->isPasswordValid($input, new User());

            return $input;
        });
        $firstName = $io->ask('Firstname', null, function ($input) {
            if (empty($input)) {
                throw new \RuntimeException('Field cannot be empty!');
            }

            return $input;
        });
        $lastName = $io->ask('Lastname', null, function ($input) {
            if (empty($input)) {
                throw new \RuntimeException('Field cannot be empty!');
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

        $role1 = new Role();
        $role1->setName('Admin');
        $role1->setRole('ROLE_ADMIN');
        $role2 = new Role();
        $role2->setName('Nutzer');
        $role2->setRole('ROLE_USER');
        $this->em->persist($role1);
        $this->em->persist($role2);

        $user = new User();
        $user->setActive(true);
        $user->setEmail($email);
        $user->setFirstname($firstName);
        $user->setLastname($lastName);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $user->setUsername($username);
        $user->setRole($role1);
        $this->em->persist($user);
        $io->note('User and Roles created.');

        $this->createTemplateTypes();
        $io->note('Templates prepared.');

        $name = $io->ask('What is the name of your accommodation', null, function ($input) {
            if (empty($input)) {
                throw new \RuntimeException('Field must not be empty!');
            }

            return $input;
        });

        $sub = new Subsidiary();
        $sub->setName($name);
        $sub->setDescription('');
        $this->em->persist($sub);
        $io->note('Accommodation prepared.');

        $this->createDummyCustomer();
        $io->note('Customers prepared.');

        $this->em->flush();

        $io->success('All done! You can now navigate to the app and login with the provided username and password.');

        return Command::SUCCESS;
    }

    private function createTemplateTypes(): void
    {
        $t1 = new TemplateType();
        $t1->setIcon('fa-envelope');
        $t1->setName('TEMPLATE_RESERVATION_EMAIL');
        $t1->setService('ReservationService');
        $t1->setEditorTemplate('editor_template_reservation.json.twig');
        $t2 = new TemplateType();
        $t2->setIcon('fa-file-pdf');
        $t2->setName('TEMPLATE_FILE_PDF');
        $t2->setService('');
        $t2->setEditorTemplate('editor_template_default.json.twig');
        $t3 = new TemplateType();
        $t3->setIcon('fa-file-pdf');
        $t3->setName('TEMPLATE_INVOICE_PDF');
        $t3->setService('InvoiceService');
        $t3->setEditorTemplate('editor_template_invoice.json.twig');
        $t4 = new TemplateType();
        $t4->setIcon('fa-file-pdf');
        $t4->setName('TEMPLATE_RESERVATION_PDF');
        $t4->setService('ReservationService');
        $t4->setEditorTemplate('editor_template_reservation.json.twig');
        $t5 = new TemplateType();
        $t5->setIcon('fa-file-pdf');
        $t5->setName('TEMPLATE_CASHJOURNAL_PDF');
        $t5->setService('CashJournalService');
        $t5->setEditorTemplate('editor_template_cashjournal.json.twig');
        $t6 = new TemplateType();
        $t6->setIcon('fa-file-pdf');
        $t6->setName('TEMPLATE_GDPR_PDF');
        $t6->setService('CustomerService');
        $t6->setEditorTemplate('editor_template_customer.json.twig');

        $this->em->persist($t1);
        $this->em->persist($t2);
        $this->em->persist($t3);
        $this->em->persist($t4);
        $this->em->persist($t5);
        $this->em->persist($t6);
    }

    private function createDummyCustomer(): void
    {
        $c = new Customer();
        $c->setFirstname('Anonym');
        $c->setLastname('Anonym');
        $c->setSalutation('Herr');
        $this->em->persist($c);
    }
}
