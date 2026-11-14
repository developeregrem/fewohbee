<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateUserCommandTest extends KernelTestCase
{
    private const PASSWORD = 'Test-user-passphrase-2026!';

    private EntityManagerInterface $em;
    private Command $command;

    protected function setUp(): void
    {
        parent::setUp();
        $kernel = self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $application = new Application($kernel);
        $this->command = $application->find('app:create-user');
    }

    /**
     * @param list<string> $answers
     * @param list<string> $expectedRoles
     */
    #[DataProvider('roleSelections')]
    public function testInteractiveCreationKeepsSelectedRolesAndHashesThePassword(array $answers, array $expectedRoles): void
    {
        $username = 'cli_'.bin2hex(random_bytes(6));
        $result = new CommandTester($this->command)->run(interactiveInputs: [
            $username,
            self::PASSWORD,
            'Test',
            'Command',
            $username.'@example.com',
            ...$answers,
        ]);

        self::assertSame(Command::SUCCESS, $result->statusCode);
        self::assertStringContainsString('User created!', $result->getDisplay());
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(User::class, $user);
        self::assertSame('Test', $user->getFirstname());
        self::assertSame('Command', $user->getLastname());
        self::assertSame($username.'@example.com', $user->getEmail());
        self::assertTrue($user->getActive());
        self::assertNotSame(self::PASSWORD, $user->getPassword());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, self::PASSWORD));
        $roles = $user->getRoleEntities()->map(static fn (Role $role): string => $role->getRole())->toArray();
        sort($roles);
        sort($expectedRoles);
        self::assertSame($expectedRoles, $roles);
    }

    /** @return iterable<string, array{list<string>, list<string>}> */
    public static function roleSelections(): iterable
    {
        yield 'default role' => [[''], ['ROLE_RESERVATIONS']];
        yield 'multiple roles' => [['ROLE_INVOICES,ROLE_RESERVATIONS_RO'], ['ROLE_INVOICES', 'ROLE_RESERVATIONS_RO']];
        yield 'invalid role is retried' => [['NOT_A_ROLE', 'ROLE_RESERVATIONS_RO'], ['ROLE_RESERVATIONS_RO']];
    }

    public function testInvalidInputsAreRetriedBeforeCreatingTheUser(): void
    {
        $username = 'cli_'.bin2hex(random_bytes(6));
        $result = new CommandTester($this->command)->run(interactiveInputs: [
            'test-admin',
            $username,
            'short',
            self::PASSWORD,
            '',
            'Test',
            '',
            'Command',
            'invalid-email',
            $username.'@example.com',
            'ROLE_RESERVATIONS_RO',
        ]);

        self::assertSame(Command::SUCCESS, $result->statusCode);
        self::assertStringContainsString('Username already exists!', $result->getDisplay());
        self::assertStringContainsString('Firstname must not be empty!', $result->getDisplay());
        self::assertStringContainsString('Lastname must not be empty!', $result->getDisplay());
        self::assertStringContainsString('You must insert a valid mail address!', $result->getDisplay());
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(User::class, $user);
        self::assertSame($username.'@example.com', $user->getEmail());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, self::PASSWORD));
        self::assertCount(1, $this->em->getRepository(User::class)->findBy(['username' => $username]));
    }

    public function testUnexpectedQuestionHelperIsRejectedBeforeAnyUserIsCreated(): void
    {
        $initialCount = $this->em->getRepository(User::class)->count([]);
        $this->command->setHelperSet(new HelperSet(['question' => new FormatterHelper()]));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The question helper must be an instance of QuestionHelper.');

        try {
            new CommandTester($this->command)->run();
        } finally {
            self::assertSame($initialCount, $this->em->getRepository(User::class)->count([]));
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->clear();
            $connection = $this->em->getConnection();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        parent::tearDown();
    }
}
