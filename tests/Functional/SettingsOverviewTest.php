<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The settings overview and the navigation entry that leads to it. */
final class SettingsOverviewTest extends WebTestCase
{
    public function testAdminSeesTheSettingsInGroups(): void
    {
        $client = $this->adminClient();

        $crawler = $client->request('GET', '/settings');

        self::assertResponseIsSuccessful();
        self::assertCount(6, $crawler->filter('.settings-group'));
        self::assertCount(1, $crawler->filter('.settings-groups a[href$="/settings/users/"]'));
        // Accounting lives below the journal but is listed among the settings
        self::assertCount(1, $crawler->filter('.settings-groups a[href$="/journal/settings"]'));
        $mcpEnabled = (bool) self::getContainer()->getParameter('mcp_enabled');
        self::assertCount($mcpEnabled ? 1 : 0, $crawler->filter('.settings-groups a[href$="/settings/mcp"]'));
        self::assertSelectorExists('.app-nav-link.active[href$="/settings"]');
    }

    public function testSettingsPageMarksTheEntryAndKeepsItsOwnTitle(): void
    {
        $client = $this->adminClient();

        $crawler = $client->request('GET', '/settings/users/');

        self::assertResponseIsSuccessful();
        $entry = $crawler->filter('.app-nav-link.active[href$="/settings"]');
        self::assertCount(1, $entry);
        self::assertNotSame(trim($entry->text()), trim($crawler->filter('.app-title')->text()));
    }

    public function testStaffWithoutAdministrationCannotOpenTheOverview(): void
    {
        $client = self::createClient();
        $client->loginUser($this->reservationsUser(), 'main');

        $client->request('GET', '/settings');

        self::assertResponseStatusCodeSame(403);
    }

    private function adminClient(): KernelBrowser
    {
        $client = self::createClient();
        $admin = $this->em()->getRepository(User::class)->findOneBy(['username' => 'test-admin']) ?? throw new \RuntimeException('Prepared test admin missing.');
        $client->loginUser($admin, 'main');

        return $client;
    }

    private function reservationsUser(): User
    {
        $em = $this->em();
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'settings-overview-staff']);
        if ($user instanceof User) {
            return $user;
        }
        $role = $em->getRepository(Role::class)->findOneBy(['role' => 'ROLE_RESERVATIONS'])
            ?? throw new \RuntimeException('Role ROLE_RESERVATIONS missing.');
        $user = new User();
        $user->setUsername('settings-overview-staff');
        $user->setFirstname('Settings');
        $user->setLastname('Staff');
        $user->setEmail('settings-overview-staff@example.com');
        $user->setPassword('unused');
        $user->setActive(true);
        $user->setRoleEntities([$role]);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
