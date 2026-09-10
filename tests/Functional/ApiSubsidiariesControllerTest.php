<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\ApiScope;
use App\Entity\Role;
use App\Entity\Subsidiary;
use App\Entity\User;
use App\Service\ApiTokenService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ApiSubsidiariesControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testUnauthenticatedReturns401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/subsidiaries');

        self::assertResponseStatusCodeSame(401);
    }

    public function testTokenWithoutSubsidiariesScopeReturns403(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::RESERVATIONS_READ->value]);

        $this->requestWithBearer($client, '/api/v1/subsidiaries', $plainToken);

        self::assertResponseStatusCodeSame(403);
    }

    public function testScopeWithoutTheUnderlyingRoleReturns403(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_CUSTOMERS'], [ApiScope::SUBSIDIARIES_READ->value]);

        $this->requestWithBearer($client, '/api/v1/subsidiaries', $plainToken);

        self::assertResponseStatusCodeSame(403);
    }

    public function testListReturnsBranchesWithTheirOpeningHours(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::SUBSIDIARIES_READ->value]);

        $name = 'Api branch '.bin2hex(random_bytes(4));
        $this->createSubsidiary($name, [1 => [['08:00', '12:00'], ['16:00', '19:00']]], 'By arrangement');

        $this->requestWithBearer($client, '/api/v1/subsidiaries', $plainToken);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertArrayHasKey('data', $payload);
        self::assertSame(\count($payload['data']), $payload['meta']['count'] ?? null);

        $row = null;
        foreach ($payload['data'] as $candidate) {
            if ($name === ($candidate['name'] ?? null)) {
                $row = $candidate;
                break;
            }
        }

        self::assertNotNull($row, 'The created branch must appear in the listing.');
        self::assertSame([
            '1' => [
                ['from' => '08:00', 'to' => '12:00'],
                ['from' => '16:00', 'to' => '19:00'],
            ],
        ], $row['openingHours']);
        self::assertSame('By arrangement', $row['openingHoursNote']);
        self::assertArrayNotHasKey('invoiceNumberPattern', $row);
    }

    public function testABranchWithoutOpeningHoursReportsNullRatherThanAnEmptyList(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::SUBSIDIARIES_READ->value]);

        $name = 'Api branch '.bin2hex(random_bytes(4));
        $this->createSubsidiary($name, [], null);

        $this->requestWithBearer($client, '/api/v1/subsidiaries', $plainToken);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        foreach ($payload['data'] as $row) {
            if ($name === ($row['name'] ?? null)) {
                self::assertNull($row['openingHours']);
                self::assertNull($row['openingHoursNote']);
                self::assertNull($row['checkInFrom']);
                self::assertNull($row['checkInUntil']);
                self::assertNull($row['checkOutUntil']);
                self::assertNull($row['checkInNote']);

                return;
            }
        }

        self::fail('The created branch must appear in the listing.');
    }

    public function testTheCheckInTimesAreListedAsPlainClockValues(): void
    {
        $client = static::createClient();
        [, $plainToken] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::SUBSIDIARIES_READ->value]);

        $name = 'Api branch '.bin2hex(random_bytes(4));
        $this->createSubsidiary($name, [], null, true);

        $this->requestWithBearer($client, '/api/v1/subsidiaries', $plainToken);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        foreach ($payload['data'] as $row) {
            if ($name === ($row['name'] ?? null)) {
                // 'HH:MM' without a date part: a consumer must not be tempted to read a
                // timezone into a house rule.
                self::assertSame('17:00', $row['checkInFrom']);
                self::assertSame('20:00', $row['checkInUntil']);
                self::assertSame('10:00', $row['checkOutUntil']);
                self::assertSame('Later arrival by arrangement', $row['checkInNote']);

                return;
            }
        }

        self::fail('The created branch must appear in the listing.');
    }

    private function requestWithBearer(KernelBrowser $client, string $uri, string $token): void
    {
        $client->request('GET', $uri, [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    /**
     * @param array<int, list<array{0: string, 1: string}>> $openingHours
     */
    private function createSubsidiary(string $name, array $openingHours, ?string $note, bool $withCheckInTimes = false): void
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();

        $subsidiary = new Subsidiary();
        $subsidiary->setName($name);
        $subsidiary->setDescription('Functional test');
        $subsidiary->setOpeningHours($openingHours);
        $subsidiary->setOpeningHoursNote($note);
        if ($withCheckInTimes) {
            $subsidiary->setCheckInFrom(new \DateTimeImmutable('17:00'));
            $subsidiary->setCheckInUntil(new \DateTimeImmutable('20:00'));
            $subsidiary->setCheckOutUntil(new \DateTimeImmutable('10:00'));
            $subsidiary->setCheckInNote('Later arrival by arrangement');
        }

        $em->persist($subsidiary);
        $em->flush();
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

        return [$user, $container->get(ApiTokenService::class)->createToken($user, 'functional-test', $scopes, null)->plainToken];
    }
}
