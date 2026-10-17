<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\Enum\ApiScope;
use App\Entity\Role;
use App\Entity\User;
use App\Service\ApiTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ApiAvailabilityControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
    }

    public function testRoomCountsPerNightWithTheAvailabilityScope(): void
    {
        $token = $this->createToken([ApiScope::AVAILABILITY_READ]);
        $room = $this->em()->getRepository(Appartment::class)->findOneBy(['active' => true], ['id' => 'ASC']);
        self::assertInstanceOf(Appartment::class, $room);
        $start = new \DateTimeImmutable('+10 days');

        $payload = $this->get($token, sprintf('/api/v1/availability?start=%s&end=%s&objectId=%d&roomCategoryId=%d',
            $start->format('Y-m-d'),
            $start->modify('+2 days')->format('Y-m-d'),
            $room->getObject()?->getId(),
            $room->getRoomCategory()?->getId(),
        ));

        self::assertResponseIsSuccessful();
        self::assertSame(3, $payload['meta']['count'], 'Both boundary nights are inclusive.');
        foreach ($payload['data'] as $night) {
            self::assertSame(['date', 'rooms', 'booked', 'blocked', 'available'], array_keys($night));
            self::assertGreaterThan(0, $night['rooms']);
            self::assertSame($night['rooms'], $night['booked'] + $night['blocked'] + $night['available']);
        }
    }

    public function testTokensThatMayReadReservationsMayReadAvailability(): void
    {
        $this->get($this->createToken([ApiScope::RESERVATIONS_READ]), '/api/v1/availability');
        self::assertResponseIsSuccessful();

        $this->get($this->createToken([ApiScope::PRICES_READ]), '/api/v1/availability');
        self::assertResponseStatusCodeSame(403);
    }

    public function testInvalidParametersAreRefused(): void
    {
        $token = $this->createToken([ApiScope::AVAILABILITY_READ]);

        $this->get($token, '/api/v1/availability?start=2027-01-01&end=2028-06-01');
        self::assertResponseStatusCodeSame(400);
        $this->get($token, '/api/v1/availability?objectId=999999');
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $token, string $uri): array
    {
        $this->client->request('GET', $uri, [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * A token of a user with read access to reservations.
     *
     * @param list<ApiScope> $scopes
     */
    private function createToken(array $scopes): string
    {
        $container = static::getContainer();
        $em = $this->em();

        $user = new User();
        $user->setUsername('api_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Api');
        $user->setLastname('Tester');
        $user->setEmail(sprintf('api+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'ChangeMe123!'));
        $user->setRoleEntities([$em->getRepository(Role::class)->findOneBy(['role' => 'ROLE_RESERVATIONS_RO']) ?? self::fail('Role ROLE_RESERVATIONS_RO missing.')]);
        $em->persist($user);
        $em->flush();

        return $container->get(ApiTokenService::class)->createToken(
            $user,
            'functional-test',
            array_map(static fn (ApiScope $scope): string => $scope->value, $scopes),
            null,
        )->plainToken;
    }
}
