<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\ApiScope;
use App\Entity\Role;
use App\Entity\User;
use App\Service\ApiTokenService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ApiReservationsControllerTest extends WebTestCase
{
    private const USER_PASSWORD = 'ChangeMe123!';

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testUnauthenticatedRequestReturns401WithChallenges(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/reservations');

        self::assertResponseStatusCodeSame(401);
        $wwwAuthenticate = (string) $client->getResponse()->headers->get('WWW-Authenticate');
        self::assertStringContainsString('Bearer', $wwwAuthenticate);
        self::assertStringContainsString('Basic', $wwwAuthenticate);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(401, $payload['error']['code'] ?? null);
    }

    public function testGarbageBearerTokenReturns401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/reservations', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fwb_'.str_repeat('0', 64),
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testBasicAuthWithRealPasswordReturns401(): void
    {
        $client = static::createClient();
        [$user] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::RESERVATIONS_READ->value]);

        $client->request('GET', '/api/v1/reservations', [], [], [
            'PHP_AUTH_USER' => $user->getUsername(),
            'PHP_AUTH_PW' => self::USER_PASSWORD,
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testBasicAuthWithWrongUsernameReturns401(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::RESERVATIONS_READ->value]);

        $client->request('GET', '/api/v1/reservations', [], [], [
            'PHP_AUTH_USER' => 'someone_else',
            'PHP_AUTH_PW' => $plainToken,
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testTokenWithoutScopeReturns403(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::CALENDAR_READ->value]);

        $this->requestWithBearer($client, '/api/v1/reservations', $plainToken);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTokenWithScopeButMissingRoleReturns403(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_CASHJOURNAL'], [ApiScope::RESERVATIONS_READ->value]);

        $this->requestWithBearer($client, '/api/v1/reservations', $plainToken);

        self::assertResponseStatusCodeSame(403);
    }

    public function testHappyPathReturnsEnvelope(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::RESERVATIONS_READ->value]);

        $this->requestWithBearer($client, '/api/v1/reservations?start=2026-01-01&end=2026-01-31', $plainToken);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload['data'] ?? null);
        self::assertSame('2026-01-01', $payload['meta']['start'] ?? null);
        self::assertSame('2026-01-31', $payload['meta']['end'] ?? null);
        self::assertSame(\count($payload['data']), $payload['meta']['count'] ?? null);
        foreach ($payload['data'] as $row) {
            self::assertArrayHasKey('startDate', $row);
            self::assertArrayHasKey('apartment', $row);
            self::assertArrayHasKey('types', $row);
            self::assertArrayNotHasKey('customers', $row);
        }
    }

    public function testBasicAuthBridgeWorksForReservations(): void
    {
        $client = static::createClient();
        [$user, $plainToken] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::RESERVATIONS_READ->value]);

        $client->request('GET', '/api/v1/reservations', [], [], [
            'PHP_AUTH_USER' => $user->getUsername(),
            'PHP_AUTH_PW' => $plainToken,
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testMalformedDateReturns400(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::RESERVATIONS_READ->value]);

        $this->requestWithBearer($client, '/api/v1/reservations?start=currywurst', $plainToken);

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('start', $payload['error']['message'] ?? '');
    }

    public function testTooLargeRangeReturns400(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::RESERVATIONS_READ->value]);

        $this->requestWithBearer($client, '/api/v1/reservations?start=2026-01-01&end=2026-12-31', $plainToken);

        self::assertResponseStatusCodeSame(400);
    }

    public function testUnknownStatusIdReturns400(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::RESERVATIONS_READ->value]);

        $this->requestWithBearer($client, '/api/v1/reservations?statusId=999999', $plainToken);

        self::assertResponseStatusCodeSame(400);
    }

    public function testApiErrorsAreJsonNotHtml(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/does-not-exist');

        // Unauthenticated → 401 from the entry point, still JSON.
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('error', $payload);
    }

    private function requestWithBearer(KernelBrowser $client, string $uri, string $token): void
    {
        $client->request('GET', $uri, [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    /**
     * @param string[]     $roleCodes
     * @param list<string> $scopes
     *
     * @return array{0: User, 1: string}
     */
    private function createUserWithToken(array $roleCodes, array $scopes): array
    {
        $container = static::getContainer();
        $doctrine = $container->get(ManagerRegistry::class);
        $em = $doctrine->getManager();
        $roleRepository = $em->getRepository(Role::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setUsername('api_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Api');
        $user->setLastname('Tester');
        $user->setEmail(sprintf('api+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($passwordHasher->hashPassword($user, self::USER_PASSWORD));

        $roles = [];
        foreach ($roleCodes as $roleCode) {
            $role = $roleRepository->findOneBy(['role' => $roleCode]);
            self::assertNotNull($role, sprintf('Role %s must exist in database.', $roleCode));
            $roles[] = $role;
        }
        $user->setRoleEntities($roles);

        $em->persist($user);
        $em->flush();

        $result = $container->get(ApiTokenService::class)->createToken($user, 'functional-test', $scopes, null);

        return [$user, $result->plainToken];
    }
}
