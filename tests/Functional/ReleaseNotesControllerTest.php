<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\User;
use App\Service\ReleaseNotesService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;

/**
 * Covers the in-app release notes and the once-per-version announcement.
 */
final class ReleaseNotesControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testTheOverviewRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/release-notes/');

        self::assertResponseRedirects();
    }

    public function testTheOverviewListsTheBundledNotes(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $crawler = $client->request('GET', '/release-notes/');

        self::assertResponseIsSuccessful();
        $version = static::getContainer()->get(ReleaseNotesService::class)->getCurrentVersion();
        self::assertStringContainsString($version, $crawler->filter('body')->text());
    }

    public function testAnUnseenVersionIsAnnouncedInTheBell(): void
    {
        $client = static::createClient();
        $releaseNotes = static::getContainer()->get(ReleaseNotesService::class);
        $version = $releaseNotes->getCurrentVersion();

        if (!$releaseNotes->hasNotesFor($version)) {
            self::markTestSkipped(sprintf('No release notes bundled for version %s', $version));
        }

        $client->loginUser($this->createUser());
        $crawler = $client->request('GET', '/notifications/panel');

        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $crawler->filter('[data-url*="release-notes"]'),
            'A user who has not seen this version must find it in the bell'
        );
    }

    public function testNoAutoOpeningPopupIsRendered(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $crawler = $client->request('GET', '/release-notes/');

        self::assertResponseIsSuccessful();
        // The bell is the single announcement surface; a popup that greets the
        // user on load would be dismissed by reflex before it is read.
        self::assertCount(0, $crawler->filter('[data-release-notes-url-value]'));
    }

    public function testDismissingTheModalClearsTheBellEntry(): void
    {
        $client = static::createClient();
        $releaseNotes = static::getContainer()->get(ReleaseNotesService::class);
        $version = $releaseNotes->getCurrentVersion();

        if (!$releaseNotes->hasNotesFor($version)) {
            self::markTestSkipped(sprintf('No release notes bundled for version %s', $version));
        }

        $user = $this->createUser();
        $client->loginUser($user);

        // The CSRF token has to come from a rendered page, same as in the browser.
        $crawler = $client->request('GET', '/release-notes/' . $version . '/modal');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('[data-release-notes-seen-token]')->attr('data-release-notes-seen-token');
        self::assertNotEmpty($token);

        $client->request('POST', '/release-notes/seen', ['_token' => $token]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame($version, $this->reload($user)->getLastSeenVersion());

        $crawler = $client->request('GET', '/notifications/panel');
        self::assertCount(
            0,
            $crawler->filter('[data-url*="release-notes"]'),
            'Once acknowledged, the entry must not come back'
        );
    }

    public function testDismissingWithoutAValidCsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $client->loginUser($user);

        $client->request('POST', '/release-notes/seen', ['_token' => 'not-a-valid-token']);

        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->reload($user)->getLastSeenVersion(), 'A rejected request must not record anything');
    }

    public function testAnUnknownVersionReturns404(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('GET', '/release-notes/0.0.0-nope/modal');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A session that expired while a remember-me cookie is present leaves the
     * user authenticated, but not *fully* authenticated. Gating on
     * IS_AUTHENTICATED_FULLY made this endpoint answer with the login page while
     * the surrounding page rendered normally — the modal then showed a login
     * form, closing it recorded nothing, and the announcement came back on every
     * reload.
     */
    public function testRememberedUsersCanStillReachTheNotes(): void
    {
        $client = static::createClient();
        $releaseNotes = static::getContainer()->get(ReleaseNotesService::class);
        $version = $releaseNotes->getCurrentVersion();

        if (!$releaseNotes->hasNotesFor($version)) {
            self::markTestSkipped(sprintf('No release notes bundled for version %s', $version));
        }

        $this->loginAsRemembered($client, $this->createUser());

        $client->request('GET', '/release-notes/' . $version . '/modal');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-release-notes-seen-url', (string) $client->getResponse()->getContent());
    }

    public function testARememberedUserCanAcknowledgeTheNotes(): void
    {
        $client = static::createClient();
        $releaseNotes = static::getContainer()->get(ReleaseNotesService::class);
        $version = $releaseNotes->getCurrentVersion();

        if (!$releaseNotes->hasNotesFor($version)) {
            self::markTestSkipped(sprintf('No release notes bundled for version %s', $version));
        }

        $user = $this->createUser();
        $this->loginAsRemembered($client, $user);

        $crawler = $client->request('GET', '/release-notes/' . $version . '/modal');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('[data-release-notes-seen-token]')->attr('data-release-notes-seen-token');

        $client->request('POST', '/release-notes/seen', ['_token' => $token]);

        // Without this the announcement can never be acknowledged, which is what
        // produced the reload loop.
        self::assertResponseStatusCodeSame(204);
        self::assertSame($version, $this->reload($user)->getLastSeenVersion());
    }

    /** Authenticates through a remember-me token, as a returning visitor would. */
    private function loginAsRemembered(KernelBrowser $client, User $user): void
    {
        $container = static::getContainer();
        $token = new RememberMeToken($user, 'main');
        $container->get('security.token_storage')->setToken($token);

        $session = $container->get('session.factory')->createSession();
        $session->set('_security_main', serialize($token));
        $session->save();

        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    /** Re-reads the user through the current kernel's entity manager. */
    private function reload(User $user): User
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }

    private function createUser(): User
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setUsername('rn_' . bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('User');
        $user->setEmail(sprintf('rn+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($passwordHasher->hashPassword($user, 'ChangeMe123!'));

        // Release notes are visible to every logged-in user, so the least
        // privileged role available is the honest fixture here.
        $role = $em->getRepository(Role::class)->findOneBy(['role' => 'ROLE_RESERVATIONS_RO']);
        $user->setRoleEntities(null !== $role ? [$role] : []);

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
