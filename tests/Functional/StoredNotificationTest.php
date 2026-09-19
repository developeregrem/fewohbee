<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\NotificationSeverity;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\NotificationCenterService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Covers notifications recorded in the database: visibility, read state and purging.
 */
final class StoredNotificationTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testAStoredNotificationShowsUpInTheBell(): void
    {
        $client = static::createClient();
        $user = $this->createUser('ROLE_ADMIN');
        $this->store('notification.stored.invoice', ['%number%' => 'RE-2026-0007']);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/notifications/panel');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('RE-2026-0007', $crawler->filter('body')->text());
    }

    public function testTheOperatorsNoteIsShownBelowTheTitle(): void
    {
        $client = static::createClient();
        $this->store('notification.stored.invoice', ['%number%' => 'NOTE-1'], note: 'Zahlungsziel überschritten');
        $client->loginUser($this->createUser('ROLE_ADMIN'));

        $crawler = $client->request('GET', '/notifications/panel');

        self::assertResponseIsSuccessful();
        // Without this, several automations all read "Invoice <number>" and the
        // user cannot tell which one fired.
        self::assertStringContainsString('Zahlungsziel überschritten', $crawler->filter('body')->text());
    }

    public function testARoleGatedNotificationStaysHiddenFromOtherRoles(): void
    {
        $client = static::createClient();
        $this->store('notification.stored.invoice', ['%number%' => 'SECRET-1'], 'ROLE_INVOICES');
        $client->loginUser($this->createUser('ROLE_CASHJOURNAL'));

        $crawler = $client->request('GET', '/notifications/panel');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('SECRET-1', $crawler->filter('body')->text());
    }

    public function testRoleInheritanceIsHonoured(): void
    {
        $client = static::createClient();
        $this->store('notification.stored.invoice', ['%number%' => 'INHERIT-1'], 'ROLE_INVOICES');
        // ROLE_ADMIN inherits ROLE_INVOICES through role_hierarchy; a plain
        // getRoles() check would miss that.
        $client->loginUser($this->createUser('ROLE_ADMIN'));

        $crawler = $client->request('GET', '/notifications/panel');

        self::assertStringContainsString('INHERIT-1', $crawler->filter('body')->text());
    }

    public function testMarkingOneAsReadRemovesItForThatUserOnly(): void
    {
        $client = static::createClient();
        $notification = $this->store('notification.stored.invoice', ['%number%' => 'READ-1']);
        $reader = $this->createUser('ROLE_ADMIN');
        $other = $this->createUser('ROLE_ADMIN');

        $client->loginUser($reader);
        $crawler = $client->request('GET', '/notifications/panel');
        $token = $crawler->filter('button[data-url*="/read"]')->attr('data-token');
        self::assertNotEmpty($token);

        $client->request('POST', '/notifications/' . $notification . '/read', ['_token' => $token]);
        self::assertResponseStatusCodeSame(204);

        $crawler = $client->request('GET', '/notifications/panel');
        self::assertStringNotContainsString('READ-1', $crawler->filter('body')->text());

        // The second user has read nothing, so the entry must still be there.
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($other);
        $crawler = $client->request('GET', '/notifications/panel');
        self::assertStringContainsString('READ-1', $crawler->filter('body')->text());
    }

    public function testReadingWithoutAValidTokenIsRejected(): void
    {
        $client = static::createClient();
        $notification = $this->store('notification.stored.invoice', ['%number%' => 'CSRF-1']);
        $client->loginUser($this->createUser('ROLE_ADMIN'));

        $client->request('POST', '/notifications/' . $notification . '/read', ['_token' => 'nope']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testPurgingRemovesOldNotificationsAndTheirReadState(): void
    {
        static::createClient();
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $repository = $container->get(NotificationRepository::class);

        $id = $this->store('notification.stored.invoice', ['%number%' => 'OLD-1']);
        $notification = $repository->find($id);
        self::assertNotNull($notification);
        $notification->setCreatedAt(new \DateTimeImmutable('-200 days'));
        $em->flush();

        $removed = $repository->purgeOlderThan(new \DateTimeImmutable('-90 days'));

        self::assertGreaterThanOrEqual(1, $removed);
        // A DQL DELETE bypasses the unit of work, so the identity map would still
        // hand back the deleted object without this.
        $em->clear();
        self::assertNull($repository->find($id));
    }

    /** @param array<string, string|int> $params */
    private function store(string $titleKey, array $params, ?string $requiredRole = null, ?string $note = null): int
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $notification = $container->get(NotificationCenterService::class)->create(
            type: 'invoice',
            titleKey: $titleKey,
            severity: NotificationSeverity::INFO,
            params: $params,
            requiredRole: $requiredRole,
            note: $note,
        );
        $em->flush();

        return (int) $notification->getId();
    }

    private function createUser(string $role): User
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();

        $user = new User();
        $user->setUsername('sn_' . bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('User');
        $user->setEmail(sprintf('sn+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'ChangeMe123!'));
        // A fresh user would otherwise also carry the unseen-release entry.
        $user->setLastSeenVersion('99.99.99');

        $roleEntity = $em->getRepository(Role::class)->findOneBy(['role' => $role]);
        $user->setRoleEntities(null !== $roleEntity ? [$roleEntity] : []);

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
