<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\EventSubscriber\LastActionSubscriber;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class LastActionSubscriberTest extends KernelTestCase
{
    private Connection $connection;

    #[DataProvider('managerStates')]
    public function testTimestampUpdateIsIsolatedFromUnflushedUserEdits(bool $closePrimary, bool $closeBackground): void
    {
        $kernel = self::bootKernel();
        $container = self::getContainer();
        $registry = $container->get(ManagerRegistry::class);
        $primary = $container->get(EntityManagerInterface::class);
        $background = $registry->getManager('background');
        self::assertInstanceOf(EntityManagerInterface::class, $background);
        self::assertNotSame($primary, $background);
        $this->connection = $primary->getConnection();
        self::assertSame($this->connection, $background->getConnection());
        $this->connection->beginTransaction();
        $user = $primary->getRepository(User::class)->findOneBy(['username' => 'test-admin']);
        self::assertInstanceOf(User::class, $user);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $originalFirstname = $user->getFirstname();
        $user->setFirstname('Unsaved activity test');
        $this->connection->update('users', ['last_action' => '2000-01-01 00:00:00'], ['id' => $userId]);
        $logCount = $this->connection->fetchOne('SELECT COUNT(*) FROM logging');
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main'));
        if ($closePrimary) {
            $primary->close();
        }
        if ($closeBackground) {
            $background->close();
        }
        $started = new \DateTimeImmutable();

        $container->get(LastActionSubscriber::class)->updateLastAction(
            new FinishRequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST),
        );

        $row = $this->connection->fetchAssociative('SELECT firstname, last_action FROM users WHERE id = ?', [$userId]);
        self::assertIsArray($row);
        $lastAction = new \DateTimeImmutable($row['last_action']);
        self::assertGreaterThanOrEqual($started->getTimestamp(), $lastAction->getTimestamp());
        self::assertLessThanOrEqual(time(), $lastAction->getTimestamp());
        self::assertSame($originalFirstname, $row['firstname']);
        self::assertSame('Unsaved activity test', $user->getFirstname());
        self::assertSame(!$closePrimary, $primary->isOpen());
        self::assertSame(!$closePrimary, $primary->contains($user));
        $activeBackground = $registry->getManager('background');
        self::assertInstanceOf(EntityManagerInterface::class, $activeBackground);
        self::assertTrue($activeBackground->isOpen());
        self::assertSame($logCount, $this->connection->fetchOne('SELECT COUNT(*) FROM logging'));
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function managerStates(): iterable
    {
        yield 'both managers open' => [false, false];
        yield 'background manager closed' => [false, true];
        yield 'primary manager closed' => [true, false];
        yield 'both managers closed' => [true, true];
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }
}
