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

final class ApiStatisticsControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testUnauthenticatedReturns401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/statistics/utilization');

        self::assertResponseStatusCodeSame(401);
    }

    public function testTokenWithoutStatisticsScopeReturns403(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_STATISTICS'], [ApiScope::RESERVATIONS_READ->value]);

        $this->requestWithBearer($client, '/api/v1/statistics/utilization', $plainToken);

        self::assertResponseStatusCodeSame(403);
    }

    public function testScopeWithoutStatisticsRoleReturns403(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::STATISTICS_READ->value]);

        $this->requestWithBearer($client, '/api/v1/statistics/utilization', $plainToken);

        self::assertResponseStatusCodeSame(403);
    }

    public function testUtilizationHappyPath(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_STATISTICS'], [ApiScope::STATISTICS_READ->value]);

        $this->requestWithBearer($client, '/api/v1/statistics/utilization?start=2026-01&end=2026-03', $plainToken);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('2026-01', $payload['meta']['start'] ?? null);
        self::assertSame(3, $payload['meta']['count'] ?? null);
        self::assertSame('2026-01', $payload['data'][0]['month'] ?? null);
        self::assertArrayHasKey('utilization', $payload['data'][0] ?? []);
    }

    public function testOriginsHappyPath(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_STATISTICS'], [ApiScope::STATISTICS_READ->value]);

        $this->requestWithBearer($client, '/api/v1/statistics/origins?start=2026-01&end=2026-12', $plainToken);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload['data'] ?? null);
        foreach ($payload['data'] as $row) {
            self::assertArrayHasKey('name', $row);
            self::assertArrayHasKey('count', $row);
        }
    }

    public function testTurnoverMonthlyHappyPath(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_STATISTICS'], [ApiScope::STATISTICS_READ->value]);

        $this->requestWithBearer($client, '/api/v1/statistics/turnover?start=2026&end=2026&granularity=month', $plainToken);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(12, $payload['meta']['count'] ?? null);
        self::assertSame('2026-01', $payload['data'][0]['month'] ?? null);
    }

    public function testMalformedMonthReturns400(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_STATISTICS'], [ApiScope::STATISTICS_READ->value]);

        $this->requestWithBearer($client, '/api/v1/statistics/utilization?start=2026-13', $plainToken);

        self::assertResponseStatusCodeSame(400);
    }

    public function testTooLargeMonthRangeReturns400(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_STATISTICS'], [ApiScope::STATISTICS_READ->value]);

        $this->requestWithBearer($client, '/api/v1/statistics/utilization?start=2020-01&end=2026-12', $plainToken);

        self::assertResponseStatusCodeSame(400);
    }

    public function testInvalidInvoiceStatusReturns400(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_STATISTICS'], [ApiScope::STATISTICS_READ->value]);

        $this->requestWithBearer($client, '/api/v1/statistics/turnover?start=2026&invoiceStatus=99', $plainToken);

        self::assertResponseStatusCodeSame(400);
    }

    public function testLegacyStatisticsRouteStillSessionBased(): void
    {
        // Regression: the old UI endpoint must not be reachable with an API token
        // (different firewall) but must keep working for session users.
        $client = static::createClient();
        [$user, $plainToken] = $this->createUserWithToken(['ROLE_STATISTICS'], [ApiScope::STATISTICS_READ->value]);

        $this->requestWithBearer($client, '/statistics/utilization/yearly?yearStart=2026&yearEnd=2026', $plainToken);
        self::assertResponseRedirects(); // session firewall redirects to login

        $client->loginUser($user);
        $client->request('GET', '/statistics/utilization/yearly?yearStart=2026&yearEnd=2026');
        self::assertResponseIsSuccessful();
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
        $user->setPassword($passwordHasher->hashPassword($user, 'ChangeMe123!'));

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
