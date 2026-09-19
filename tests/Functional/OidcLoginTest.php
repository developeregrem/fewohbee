<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Log;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers the login page in all three single sign-on states (off, on, enforced)
 * and the guarantee that enforcing SSO really closes password login rather than
 * only hiding the form.
 *
 * The OIDC_* settings are environment driven and resolved at runtime, so each
 * case sets them before booting a fresh kernel.
 */
final class OidcLoginTest extends WebTestCase
{
    private const TEST_USER = 'test-admin';
    private const TEST_PASSWORD = 'ChangeMe123!';

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();

        foreach (['OIDC_ENABLED', 'OIDC_ISSUER', 'OIDC_CLIENT_ID', 'OIDC_CLIENT_SECRET', 'OIDC_ENFORCE'] as $name) {
            $this->originalEnv[$name] = $_ENV[$name] ?? false;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            if (false === $value) {
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }

        parent::tearDown();
    }

    private function configureOidc(bool $enabled, bool $enforce = false): void
    {
        self::ensureKernelShutdown();

        $set = static function (string $name, string $value): void {
            $_ENV[$name] = $_SERVER[$name] = $value;
        };

        $set('OIDC_ENABLED', $enabled ? 'true' : 'false');
        $set('OIDC_ENFORCE', $enforce ? 'true' : 'false');
        $set('OIDC_ISSUER', 'https://id.example.test');
        $set('OIDC_CLIENT_ID', 'fewohbee-test');
        $set('OIDC_CLIENT_SECRET', 'test-secret');
    }

    /**
     * Posts credentials straight to the firewall, the way an attacker would
     * once the form is gone. The Referer satisfies the stateless CSRF check, so
     * the request is rejected on its merits rather than on a missing token.
     */
    private function postCredentials(KernelBrowser $client, string $csrfToken): void
    {
        $client->request(
            'POST',
            '/login',
            ['_username' => self::TEST_USER, '_password' => self::TEST_PASSWORD, '_csrf_token' => $csrfToken],
            [],
            ['HTTP_REFERER' => 'http://localhost/login'],
        );
    }

    private function extractCsrfToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $field = $crawler->filter('input[name="_csrf_token"]');
        self::assertCount(1, $field, 'The login form must expose a CSRF token while password login is enabled.');

        return $field->attr('value') ?? '';
    }

    public function testLoginPageIsUnchangedWhenSingleSignOnIsDisabled(): void
    {
        $this->configureOidc(enabled: false);
        $client = static::createClient();

        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="_username"]'));
        self::assertCount(1, $crawler->filter('input[name="_password"]'));
        self::assertStringNotContainsString('/login/oidc/start', $client->getResponse()->getContent() ?: '');
    }

    public function testStartRouteIsHiddenWhenSingleSignOnIsDisabled(): void
    {
        $this->configureOidc(enabled: false);
        $client = static::createClient();

        $client->request('GET', '/login/oidc/start');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The authenticator declines the callback while single sign-on is off, so
     * the request reaches the controller action. It must answer 404 rather than
     * fail with "the controller returned no response".
     */
    public function testCallbackRouteIsHiddenWhenSingleSignOnIsDisabled(): void
    {
        $this->configureOidc(enabled: false);
        $client = static::createClient();

        $client->request('GET', '/login/oidc/callback', ['code' => 'x', 'state' => 'y']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testLoginPageOffersSingleSignOnWhenEnabled(): void
    {
        $this->configureOidc(enabled: true);
        $client = static::createClient();

        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/login/oidc/start', $client->getResponse()->getContent() ?: '');
        // Password login stays available unless it is explicitly enforced away.
        self::assertCount(1, $crawler->filter('input[name="_password"]'));
    }

    public function testEnforcedModeRemovesThePasswordForm(): void
    {
        $this->configureOidc(enabled: true, enforce: true);
        $client = static::createClient();

        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="_username"]'));
        self::assertCount(0, $crawler->filter('input[name="_password"]'));
        self::assertCount(0, $crawler->filter('input[name="_remember_me"]'));
        self::assertStringContainsString('/login/oidc/start', $client->getResponse()->getContent() ?: '');
    }

    /**
     * There is deliberately no query parameter, header or alternate route that
     * brings the password form back — recovery is an .env change.
     */
    public function testEnforcedModeHasNoBypassQueryParameter(): void
    {
        $this->configureOidc(enabled: true, enforce: true);
        $client = static::createClient();

        foreach (['/login?local=1', '/login?local', '/login?password=1'] as $url) {
            $crawler = $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('input[name="_password"]'), sprintf('"%s" must not reveal the password form.', $url));
        }
    }

    public function testEnforcedModeDisablesPasswordReset(): void
    {
        $this->configureOidc(enabled: true, enforce: true);
        $client = static::createClient();

        $client->request('GET', '/reset-password/');

        self::assertResponseStatusCodeSame(404);
    }

    public function testPasswordResetStaysAvailableWhenNotEnforced(): void
    {
        $this->configureOidc(enabled: true);
        $client = static::createClient();

        $client->request('GET', '/reset-password/');

        self::assertResponseIsSuccessful();
    }

    /**
     * Pulls the popover's hidden _token value out of the overview markup. The
     * popover body is embedded in a data-bs-content attribute, so its quotes
     * arrive HTML-escaped.
     */
    private static function extractDeleteToken(string $html): string
    {
        self::assertMatchesRegularExpression('/name=(?:"|&quot;)_token(?:"|&quot;)\s+value=(?:"|&quot;)([^"&]+)/', $html);
        preg_match('/name=(?:"|&quot;)_token(?:"|&quot;)\s+value=(?:"|&quot;)([^"&]+)/', $html, $matches);

        return $matches[1];
    }

    /**
     * The identity provider's "sub" is a stable pseudonymous identifier for a
     * person, so it must not end up in the change log in the clear. The field
     * name still has to appear, otherwise the linking event itself would drop
     * out of the audit trail.
     */
    public function testTheOidcSubjectIsRedactedInTheChangeLog(): void
    {
        $this->configureOidc(enabled: true);
        $client = static::createClient();

        $manager = $client->getContainer()->get(ManagerRegistry::class)->getManager();
        $user = $this->getTestUser($client);
        $user->linkOidcIdentity('https://id.example.test', 'a-very-identifying-subject');
        $manager->flush();

        $log = $manager->getRepository(Log::class)->findOneBy(
            ['entityClass' => User::class, 'entityId' => (string) $user->getId()],
            ['id' => 'DESC'],
        );
        self::assertNotNull($log, 'Linking an SSO identity must be recorded in the change log.');

        $changes = $log->getChanges() ?? [];
        self::assertArrayHasKey('oidcSubject', $changes, 'The linking event must stay visible.');
        self::assertSame(['***redacted***', '***redacted***'], $changes['oidcSubject']);
        self::assertStringNotContainsString('a-very-identifying-subject', json_encode($changes, \JSON_THROW_ON_ERROR));

        // The issuer is provider configuration, identical for every user, and
        // useful for auditing which identity provider a binding points at.
        self::assertSame('https://id.example.test', $changes['oidcIssuer'][1] ?? null);

        $user->unlinkOidcIdentity();
        $manager->flush();
    }

    private function getTestUser(KernelBrowser $client): User
    {
        $user = $client->getContainer()->get(ManagerRegistry::class)->getManager()
            ->getRepository(User::class)->findOneBy(['username' => self::TEST_USER]);
        self::assertNotNull($user, 'Test user "test-admin" must exist (run bin/run-tests.sh).');

        return $user;
    }

    /**
     * An administrator must be able to release a wrong or outdated binding, for
     * instance when a member of staff is replaced and inherits the mailbox.
     */
    public function testAdministratorCanUnlinkAnSsoAccount(): void
    {
        $this->configureOidc(enabled: true);
        $client = static::createClient();

        $manager = $client->getContainer()->get(ManagerRegistry::class)->getManager();
        $user = $this->getTestUser($client);
        $user->linkOidcIdentity('https://id.example.test', 'subject-to-release');
        $manager->flush();

        $client->loginUser($user);

        $crawler = $client->request('GET', '/settings/users/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/settings/users/'.$user->getId().'/unlink-sso', $client->getResponse()->getContent() ?: '');
        self::assertGreaterThan(0, $crawler->filter('.fa-link-slash')->count(), 'A linked account must offer the unlink action.');

        // The delete popover uses a session-bound CSRF token, so it has to come
        // from the rendered page rather than from the token manager directly.
        $token = self::extractDeleteToken($client->getResponse()->getContent() ?: '');
        $client->request(
            'DELETE',
            '/settings/users/'.$user->getId().'/unlink-sso',
            ['_token' => $token],
            [],
            ['HTTP_REFERER' => 'http://localhost/settings/users/'],
        );

        self::assertResponseStatusCodeSame(204);

        $manager->clear();
        $reloaded = $this->getTestUser($client);
        self::assertFalse($reloaded->isLinkedToOidc());
        self::assertNull($reloaded->getOidcSubject());
    }

    public function testUnlinkRequiresAValidCsrfToken(): void
    {
        $this->configureOidc(enabled: true);
        $client = static::createClient();

        $manager = $client->getContainer()->get(ManagerRegistry::class)->getManager();
        $user = $this->getTestUser($client);
        $user->linkOidcIdentity('https://id.example.test', 'subject-to-keep');
        $manager->flush();

        $client->loginUser($user);
        $client->request('DELETE', '/settings/users/'.$user->getId().'/unlink-sso', ['_token' => 'forged']);

        $manager->clear();
        self::assertTrue($this->getTestUser($client)->isLinkedToOidc(), 'A forged token must leave the binding untouched.');

        // Leave the seeded user unlinked for any test running after this one.
        $user = $this->getTestUser($client);
        $user->unlinkOidcIdentity();
        $client->getContainer()->get(ManagerRegistry::class)->getManager()->flush();
    }

    /**
     * The core guarantee: the very same POST that signs a user in while enforce
     * is off must fail once it is on. Running both halves proves the refusal
     * comes from the enforce guard and not from a malformed request.
     */
    public function testEnforcedModeRefusesCredentialsPostedDirectly(): void
    {
        $this->configureOidc(enabled: true);
        $client = static::createClient();
        $csrfToken = $this->extractCsrfToken($client);

        $this->postCredentials($client, $csrfToken);
        self::assertResponseRedirects('/dashboard', null, 'Password login must work while enforce is off.');

        $this->configureOidc(enabled: true, enforce: true);
        $client = static::createClient();

        $this->postCredentials($client, $csrfToken);

        self::assertResponseRedirects(
            'http://localhost/login',
            null,
            'With OIDC_ENFORCE the firewall must reject credentials even when they are posted directly.',
        );

        // And the rejected attempt must not have produced a session either.
        $client->request('GET', '/profile/');
        self::assertResponseRedirects('http://localhost/login');
    }
}
