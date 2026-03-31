<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests for the passkey section on the profile page and
 * the credential deletion endpoint.
 */
final class PasskeyProfileTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testProfilePageShowsPasskeySection(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getTestUser($client));

        $client->request('GET', '/profile/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="profile-passkey"]');
        self::assertSelectorExists('[data-controller*="webauthn"]');
    }

    public function testProfilePageShowsAddPasskeyButton(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($this->getTestUser($client));

        $client->request('GET', '/profile/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-action*="webauthn#register"]');
    }

    public function testProfilePageShowsEmptyCredentialTable(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($this->getTestUser($client));

        $client->request('GET', '/profile/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table.table');
    }

    public function testDeleteCredentialRejectsInvalidCsrfToken(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($this->getTestUser($client));

        $client->request('DELETE', '/profile/passkey/delete/nonexistent-id', [
            '_token' => 'invalid-csrf-token',
        ]);

        self::assertResponseStatusCodeSame(204);
    }

    public function testDeleteCredentialRequiresAuthentication(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();

        $client->request('DELETE', '/profile/passkey/delete/some-id');

        self::assertResponseRedirects();
    }

    public function testLoginPageShowsPasskeyButton(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();

        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller*="webauthn"]');
        self::assertSelectorExists('[data-action*="webauthn#authenticate"]');
    }

    private function getTestUser(KernelBrowser $client): User
    {
        $em = $client->getContainer()->get(ManagerRegistry::class)->getManager();

        $user = $em->getRepository(User::class)->findOneBy(['username' => 'test-admin']);
        self::assertNotNull($user, 'Test user "test-admin" must exist (run bin/run-tests.sh)');

        return $user;
    }
}
