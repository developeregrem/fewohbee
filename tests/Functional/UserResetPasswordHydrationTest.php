<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ResetPasswordRequest;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use SymfonyCasts\Bundle\ResetPassword\Exception\InvalidResetPasswordTokenException;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

final class UserResetPasswordHydrationTest extends KernelTestCase
{
    public function testUserAndResetRequestSurviveHydrationWithoutUpdatesAndTheTokenIsSingleUse(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        $connection->beginTransaction();

        try {
            $role = $em->getRepository(Role::class)->findOneBy(['role' => 'ROLE_RESERVATIONS_RO']);
            self::assertInstanceOf(Role::class, $role);
            $username = 'hydration_'.bin2hex(random_bytes(6));
            $user = (new User())
                ->setUsername($username)
                ->setPassword('synthetic-stored-hash')
                ->setFirstname('Test')
                ->setLastname('Hydration')
                ->setEmail($username.'@example.com')
                ->setActive(true)
                ->setThemePreference('dark')
                ->setLastAction(new \DateTime('2031-06-01 12:00:00'))
                ->setLastSeenVersion('4.14.0')
                ->addRole($role)
                ->linkOidcIdentity('https://id.example.com', 'synthetic-subject');
            $linkedAt = $user->getOidcLinkedAt()?->format('Y-m-d H:i:s');
            $em->persist($user);
            $em->flush();
            $userId = $user->getId();
            $helper = self::getContainer()->get(ResetPasswordHelperInterface::class);
            // Exercise the persistence helper directly; no reset email is sent.
            $token = $helper->generateResetToken($user);
            $request = $em->getRepository(ResetPasswordRequest::class)->findOneBy(['user' => $user]);
            self::assertInstanceOf(ResetPasswordRequest::class, $request);
            $requestId = $request->getId();
            $hash = $request->getHashedToken();
            $requestedAt = $request->getRequestedAt()->format('Y-m-d H:i:s');
            $em->clear();

            $request = $em->find(ResetPasswordRequest::class, $requestId);
            self::assertInstanceOf(ResetPasswordRequest::class, $request);
            $user = $request->getUser();
            self::assertInstanceOf(User::class, $user);
            self::assertSame($userId, $user->getId());
            self::assertSame($username, $user->getUserIdentifier());
            self::assertSame('synthetic-stored-hash', $user->getPassword());
            self::assertSame('Test', $user->getFirstname());
            self::assertSame('Hydration', $user->getLastname());
            self::assertSame($username.'@example.com', $user->getEmail());
            self::assertTrue($user->getActive());
            self::assertSame('dark', $user->getThemePreference());
            self::assertSame('2031-06-01 12:00:00', $user->getLastAction()?->format('Y-m-d H:i:s'));
            self::assertSame('4.14.0', $user->getLastSeenVersion());
            self::assertTrue($user->isLinkedToOidc());
            self::assertSame('https://id.example.com', $user->getOidcIssuer());
            self::assertSame('synthetic-subject', $user->getOidcSubject());
            self::assertSame($linkedAt, $user->getOidcLinkedAt()?->format('Y-m-d H:i:s'));
            $roles = $user->getRoleEntities();
            self::assertInstanceOf(PersistentCollection::class, $roles);
            self::assertFalse($roles->isInitialized());
            self::assertSame(['ROLE_RESERVATIONS_RO'], $user->getRoles());
            self::assertFalse($roles->isDirty());
            self::assertSame($hash, $request->getHashedToken());
            self::assertSame($requestedAt, $request->getRequestedAt()->format('Y-m-d H:i:s'));
            self::assertSame($token->getExpiresAt()->format('Y-m-d H:i:s'), $request->getExpiresAt()->format('Y-m-d H:i:s'));
            self::assertFalse($request->isExpired());
            self::assertSame($user, $helper->validateTokenAndFetchUser($token->getToken()));
            $uow = $em->getUnitOfWork();
            $uow->computeChangeSets();
            self::assertSame([], $uow->getEntityChangeSet($user));
            self::assertSame([], $uow->getEntityChangeSet($request));
            self::assertSame([], $uow->getScheduledEntityUpdates());
            self::assertSame([], $uow->getScheduledCollectionUpdates());
            $em->flush();

            $helper->removeResetRequest($token->getToken());
            $em->clear();
            self::assertNull($em->find(ResetPasswordRequest::class, $requestId));
            $this->expectException(InvalidResetPasswordTokenException::class);

            $helper->validateTokenAndFetchUser($token->getToken());
        } finally {
            $em->clear();
            $connection->rollBack();
        }
    }
}
