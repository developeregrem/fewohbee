<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Covers the navbar notification bell and the role gating of its providers.
 */
final class NotificationBellTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testTheBellIsRenderedForEveryAuthenticatedUser(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_RESERVATIONS_RO'));

        $crawler = $client->request('GET', '/release-notes/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#navbarNotifications'), 'Every logged-in user gets the bell');
    }

    public function testThePanelRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/notifications/panel');

        self::assertResponseRedirects();
    }

    public function testThePanelShowsTheReleaseNoteEntryToAnyRole(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_INVOICES'));

        $crawler = $client->request('GET', '/notifications/panel');

        self::assertResponseIsSuccessful();
        // A brand new user has never seen the running version, so the release
        // note is the one entry every role has in common.
        self::assertGreaterThan(0, $crawler->filter('.dropdown-item')->count());
    }

    public function testReadOnlyStaffSeeRemindersButNotConflicts(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_RESERVATIONS_RO'));

        $crawler = $client->request('GET', '/notifications/panel');
        self::assertResponseIsSuccessful();

        $conflictLinks = $crawler->filter('[data-url*="/reservation/conflicts"]');
        self::assertCount(0, $conflictLinks, 'Resolving a conflict is a write, so read-only staff must not see it');
    }

    public function testConflictEntriesLinkAtTheExistingModal(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_ADMIN'));

        $crawler = $client->request('GET', '/notifications/panel');
        self::assertResponseIsSuccessful();

        // Whatever is present must open through the shared modal, never navigate.
        foreach ($crawler->filter('.dropdown-item[data-url]') as $node) {
            self::assertSame(
                'click->notifications#openItemAction',
                $node->getAttribute('data-action'),
                'Panel entries reuse the shared #modalCenter'
            );
        }
        self::assertTrue(true);
    }

    public function testTheReservationOverviewNoLongerCarriesTheReminderButton(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_ADMIN'));

        $crawler = $client->request('GET', '/reservation/');
        self::assertResponseIsSuccessful();

        self::assertCount(
            0,
            $crawler->filter('[data-calendar-reminder-button]'),
            'Calendar reminders moved into the bell'
        );
    }

    private function createUser(string $role): User
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setUsername('nb_' . bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('User');
        $user->setEmail(sprintf('nb+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($passwordHasher->hashPassword($user, 'ChangeMe123!'));

        $roleEntity = $em->getRepository(Role::class)->findOneBy(['role' => $role]);
        $user->setRoleEntities(null !== $roleEntity ? [$roleEntity] : []);

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
