<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WebauthnControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    // ── Registration Options ────────────────────────────────────────────

    public function testRegistrationOptionsRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('POST', '/profile/security/devices/add/options');

        // Unauthenticated users should be redirected to login
        self::assertResponseRedirects();
    }

    public function testRegistrationOptionsReturnsJsonWhenAuthenticated(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($this->getTestUser($client));

        $client->request('POST', '/profile/security/devices/add/options', server: [
            'CONTENT_TYPE' => 'application/json',
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('rp', $data);
        self::assertArrayHasKey('user', $data);
        self::assertArrayHasKey('challenge', $data);
        self::assertArrayHasKey('pubKeyCredParams', $data);
        self::assertSame('localhost', $data['rp']['id']);
    }

    public function testRegistrationOptionsStoresChallengeInSession(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($this->getTestUser($client));

        $client->request('POST', '/profile/security/devices/add/options', server: [
            'CONTENT_TYPE' => 'application/json',
        ]);

        self::assertResponseIsSuccessful();

        $session = $client->getRequest()->getSession();
        $storedOptions = $session->get('webauthn_registration_options');
        self::assertNotNull($storedOptions, 'Registration options should be stored in session');
        self::assertJson($storedOptions);
    }

    // ── Registration Verify ─────────────────────────────────────────────

    public function testRegistrationVerifyRequiresAuthentication(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('POST', '/profile/security/devices/add');

        self::assertResponseRedirects();
    }

    public function testRegistrationVerifyRejectsMissingChallenge(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($this->getTestUser($client));

        $client->request('POST', '/profile/security/devices/add', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '{}');

        self::assertResponseStatusCodeSame(400);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    // ── Authentication Options ──────────────────────────────────────────

    public function testAuthenticationOptionsReturnsJsonWithoutLogin(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();

        $client->request('POST', '/login/webauthn/options', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '{}');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('challenge', $data);
        self::assertArrayHasKey('rpId', $data);
        self::assertSame('localhost', $data['rpId']);
        self::assertEmpty($data['allowCredentials'] ?? []);
    }

    public function testAuthenticationOptionsStoresChallengeInSession(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();

        $client->request('POST', '/login/webauthn/options', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '{}');

        self::assertResponseIsSuccessful();

        $session = $client->getRequest()->getSession();
        $storedOptions = $session->get('webauthn_authentication_options');
        self::assertNotNull($storedOptions, 'Authentication options should be stored in session');
        self::assertJson($storedOptions);
    }

    public function testAuthenticationOptionsWithUsernameIncludesUser(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();

        $client->request('POST', '/login/webauthn/options', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['username' => 'test-admin']));

        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('challenge', $data);
    }

    // ── Authentication Verify ───────────────────────────────────────────

    public function testAuthenticationVerifyRejectsWithoutChallenge(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();

        $client->request('POST', '/login/webauthn', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '{}');

        self::assertResponseStatusCodeSame(401);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function getTestUser(KernelBrowser $client): User
    {
        $em = $client->getContainer()->get(ManagerRegistry::class)->getManager();

        $user = $em->getRepository(User::class)->findOneBy(['username' => 'test-admin']);
        self::assertNotNull($user, 'Test user "test-admin" must exist (run bin/run-tests.sh)');

        return $user;
    }
}
