<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\User;
use App\EventSubscriber\LastActionSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

final class LastActionSubscriberTest extends TestCase
{
    #[DataProvider('usersWithoutAnActivityRecord')]
    public function testSkipsRequestsWithoutAPersistedApplicationUser(?UserInterface $user): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('getManager');
        $registry->expects(self::never())->method('resetManager');

        $this->runSubscriber($registry, $user);
    }

    /** @return iterable<string, array{UserInterface|null}> */
    public static function usersWithoutAnActivityRecord(): iterable
    {
        yield 'anonymous request' => [null];
        yield 'different user implementation' => [new InMemoryUser('external', null)];
        yield 'user without an id' => [new User()];
    }

    public function testAnUnexpectedManagerIsResetBeforeUpdatingTheTimestamp(): void
    {
        $manager = $this->createStub(ObjectManager::class);
        $replacement = $this->createMock(EntityManagerInterface::class);
        $query = $this->createMock(Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->expects(self::once())->method('execute')->willReturn(1);
        $replacement->expects(self::once())->method('createQuery')->willReturn($query);
        $replacement->expects(self::never())->method('flush');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('getManager')->with('background')->willReturn($manager);
        $registry->expects(self::once())->method('resetManager')->with('background')->willReturn($replacement);

        $this->runSubscriber($registry, new User()->setId(42));
    }

    public function testSkipsTheUpdateWhenResetDoesNotProvideAnOrmManager(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(false);
        $manager->expects(self::never())->method('createQuery');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('getManager')->with('background')->willReturn($manager);
        $registry->expects(self::once())->method('resetManager')->with('background')
            ->willReturn($this->createStub(ObjectManager::class));

        $this->runSubscriber($registry, new User()->setId(42));
    }

    public function testManagerLookupFailureDoesNotBlockTheRequest(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('getManager')->with('background')
            ->willThrowException(new \RuntimeException('Manager unavailable.'));
        $registry->expects(self::never())->method('resetManager');

        $this->runSubscriber($registry, new User()->setId(42));
    }

    public function testManagerResetFailureDoesNotBlockTheRequest(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(false);
        $manager->expects(self::never())->method('createQuery');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('getManager')->with('background')->willReturn($manager);
        $registry->expects(self::once())->method('resetManager')->with('background')
            ->willThrowException(new \RuntimeException('Reset failed.'));

        $this->runSubscriber($registry, new User()->setId(42));
    }

    public function testQueryFailureDoesNotBlockTheRequest(): void
    {
        $query = $this->createMock(Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->expects(self::once())->method('execute')
            ->willThrowException(new \RuntimeException('Update failed.'));
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->expects(self::once())->method('createQuery')->willReturn($query);
        $manager->expects(self::never())->method('flush');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('getManager')->with('background')->willReturn($manager);
        $registry->expects(self::never())->method('resetManager');

        $this->runSubscriber($registry, new User()->setId(42));
    }

    private function runSubscriber(ManagerRegistry $registry, ?UserInterface $user): void
    {
        $tokenStorage = new TokenStorage();
        if (null !== $user) {
            $tokenStorage->setToken(new UsernamePasswordToken($user, 'main'));
        }
        $event = new FinishRequestEvent(
            $this->createStub(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::MAIN_REQUEST,
        );

        new LastActionSubscriber($registry, $tokenStorage)->updateLastAction($event);
    }
}
