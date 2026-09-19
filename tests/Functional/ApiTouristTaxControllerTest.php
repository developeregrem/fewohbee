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

final class ApiTouristTaxControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testUnauthenticatedReturns401(): void
    {
        $client = static::createClient();
        foreach (['/api/v1/tourist-taxes', '/api/v1/tourist-taxes/report'] as $uri) {
            $client->request('GET', $uri);
            self::assertResponseStatusCodeSame(401, $uri);
        }
    }

    public function testTokenWithoutTouristTaxScopeReturns403(): void
    {
        $client = static::createClient();
        [, $token] = $this->createUserWithToken(['ROLE_OPERATIONS'], [ApiScope::PRICES_READ->value]);

        $this->requestWithBearer($client, '/api/v1/tourist-taxes', $token);

        self::assertResponseStatusCodeSame(403);
    }

    public function testScopeWithoutOperationsRoleReturns403(): void
    {
        $client = static::createClient();
        [, $token] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::TOURIST_TAX_READ->value]);

        $this->requestWithBearer($client, '/api/v1/tourist-taxes', $token);

        self::assertResponseStatusCodeSame(403);
    }

    public function testListReturnsConfiguredTaxes(): void
    {
        $client = static::createClient();
        [, $token] = $this->createUserWithToken(['ROLE_OPERATIONS'], [ApiScope::TOURIST_TAX_READ->value]);

        $this->requestWithBearer($client, '/api/v1/tourist-taxes', $token);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload['data']);
        self::assertSame(\count($payload['data']), $payload['meta']['count']);

        foreach ($payload['data'] as $tax) {
            foreach ([
                'id', 'name', 'active', 'calculationMode', 'percentageRate', 'percentageBase',
                'appliesOnlyToAdult', 'includesVat', 'validFrom', 'validTo', 'sortOrder',
                'subsidiaries', 'taxRate', 'rates',
            ] as $key) {
                self::assertArrayHasKey($key, $tax);
            }
            self::assertContains($tax['calculationMode'], ['per_night_flat', 'percent_per_room']);
        }
    }

    public function testReportReturnsOneEntryPerMonth(): void
    {
        $client = static::createClient();
        [, $token] = $this->createUserWithToken(['ROLE_OPERATIONS'], [ApiScope::TOURIST_TAX_READ->value]);

        $start = (new \DateTimeImmutable('first day of this month'))->modify('-2 months');
        $uri = sprintf(
            '/api/v1/tourist-taxes/report?start=%s&end=%s',
            $start->format('Y-m'),
            (new \DateTimeImmutable('first day of this month'))->format('Y-m'),
        );
        $this->requestWithBearer($client, $uri, $token);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertCount(3, $payload['data'], 'Both boundary months are inclusive.');
        self::assertSame($start->format('Y-m'), $payload['data'][0]['month']);
        self::assertArrayHasKey('guestCategories', $payload['meta']);

        foreach ($payload['data'] as $month) {
            self::assertArrayHasKey('taxes', $month);
            self::assertArrayHasKey('total', $month);
            self::assertEqualsWithDelta(
                array_sum(array_column($month['taxes'], 'totalAmount')),
                $month['total'],
                0.01
            );
        }
    }

    public function testReportRejectsInvalidParameters(): void
    {
        $client = static::createClient();
        [, $token] = $this->createUserWithToken(['ROLE_OPERATIONS'], [ApiScope::TOURIST_TAX_READ->value]);

        $cases = [
            '/api/v1/tourist-taxes/report?start=2026-13',
            '/api/v1/tourist-taxes/report?start=nonsense',
            '/api/v1/tourist-taxes/report?start=2026-05&end=2026-01',
            '/api/v1/tourist-taxes/report?start=2020-01&end=2026-12',
            '/api/v1/tourist-taxes/report?objectId=999999',
        ];

        foreach ($cases as $uri) {
            $this->requestWithBearer($client, $uri, $token);
            self::assertResponseStatusCodeSame(400, $uri);
        }
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
        $em = $container->get(ManagerRegistry::class)->getManager();
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
