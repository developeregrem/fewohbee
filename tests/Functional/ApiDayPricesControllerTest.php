<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\DayPrice;
use App\Entity\Enum\ApiScope;
use App\Entity\Enum\DayPriceSource;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\PriceRepository;
use App\Service\ApiTokenService;
use App\Service\Pricing\DayPriceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ApiDayPricesControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        $this->em()->createQuery('DELETE FROM App\Entity\DayPrice d')->execute();
        parent::tearDown();
    }

    public function testChangingDayPricesNeedsTheScopeTheRoleAndABearerToken(): void
    {
        $body = $this->body(['+600 days' => 120.0]);

        [, $staffToken] = $this->createUserWithToken(['ROLE_RESERVATIONS'], [ApiScope::PRICES_WRITE]);
        $this->put($staffToken, $body);
        self::assertResponseStatusCodeSame(403);

        [, $readToken] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::PRICES_READ]);
        $this->put($readToken, $body);
        self::assertResponseStatusCodeSame(403);

        [$admin, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::PRICES_WRITE]);
        $this->client->request('PUT', '/api/v1/day-prices', [], [], [
            'PHP_AUTH_USER' => $admin->getUsername(),
            'PHP_AUTH_PW' => $token,
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode($body));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('PUT', '/api/v1/day-prices', ['nights' => 'x'], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(415);

        self::assertSame([], $this->storedDayPrices());
    }

    public function testDayPricesAreSetReadAndRemoved(): void
    {
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::PRICES_READ, ApiScope::PRICES_WRITE]);
        $body = $this->body(['+600 days' => null, '+601 days' => null, '+602 days' => null]);
        [$normal, $high, $none] = array_column($body['nights'], 'night');
        $listPrice = $this->rate($token, $body, $normal)['perNight'];
        self::assertGreaterThan(0, $listPrice);
        $body['nights'][0]['amount'] = round($listPrice * 1.1, 2);
        $body['nights'][1]['amount'] = round($listPrice * 10, 2);

        $result = $this->put($token, $body);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $result['data']['created']);
        self::assertSame(1, $result['data']['unchanged'], 'There is no day price to remove.');
        // Prices from programs are held within the limits of the price rules.
        self::assertSame([$high], array_column($result['data']['limited'], 'night'));
        self::assertLessThan($body['nights'][1]['amount'], $result['data']['limited'][0]['applied']);

        // Sending the same prices again changes nothing.
        $again = $this->put($token, $body);
        self::assertSame([0, 0, 0, 3], [$again['data']['created'], $again['data']['updated'], $again['data']['removed'], $again['data']['unchanged']]);

        $this->client->request('GET', sprintf('/api/v1/day-prices?start=%s&end=%s&roomCategoryId=%d', $normal, $none, $body['roomCategoryId']), [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $listed = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        self::assertSame([$normal, $high], array_column($listed, 'night'));
        self::assertSame(['api', 'functional-test', $body['persons']], [$listed[0]['source'], $listed[0]['sourceLabel'], $listed[0]['persons']]);

        // The rate calendar of the branch shows the day price.
        $rate = $this->rate($token, $body, $normal);
        self::assertSame('api', $rate['dayPrice']['source']);
        self::assertEqualsWithDelta($body['nights'][0]['amount'], $rate['perNight'], 0.01);

        $removed = $this->put($token, ['nights' => [['night' => $normal, 'amount' => null]]] + $body);
        self::assertSame(1, $removed['data']['removed']);
        self::assertSame([$high], array_keys($this->storedDayPrices()));
    }

    public function testDayPricesSetByHandAreOnlyReplacedOnRequest(): void
    {
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::PRICES_WRITE]);
        $body = $this->body(['+610 days' => 130.0]);
        $night = $body['nights'][0]['night'];
        $room = $this->room();
        $manual = new DayPrice($room->getObject() ?? self::fail('Room without branch.'), $room->getRoomCategory() ?? self::fail('Room without category.'), new \DateTimeImmutable($night));
        $manual->set(150.0, $body['persons'], DayPriceSource::MANUAL, null, new \DateTimeImmutable());
        $this->em()->persist($manual);
        $this->em()->flush();

        $kept = $this->put($token, $body);
        self::assertSame([$night], $kept['data']['skipped']['manual']);
        self::assertSame([$night => [150.0, DayPriceSource::MANUAL]], $this->storedDayPrices());

        $replaced = $this->put($token, $body + ['overwriteManual' => true]);
        self::assertSame(1, $replaced['data']['updated']);
        self::assertSame([$night => [130.0, DayPriceSource::API]], $this->storedDayPrices());
    }

    public function testAnInvalidEntrySavesNothing(): void
    {
        [, $token] = $this->createUserWithToken(['ROLE_ADMIN'], [ApiScope::PRICES_WRITE]);
        $body = $this->body(['+620 days' => 120.0, '+621 days' => -5.0]);
        $body['nights'][] = $body['nights'][0];
        $body['nights'][] = ['night' => '2027-02-30', 'amount' => 99];

        $result = $this->put($token, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['nights[1].amount', 'nights[2].night', 'nights[3].night'],
            array_column($result['error']['details'], 'field'),
        );
        self::assertSame([], $this->storedDayPrices());

        $this->put($token, ['roomCategoryId' => 999999] + $body);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * A request body for the room category and branch of the first active room.
     *
     * @param array<string, float|null> $amounts keyed by date offset, e.g. '+600 days'
     *
     * @return array{objectId: int, roomCategoryId: int, persons: int, nights: list<array{night: string, amount: float|null}>}
     */
    private function body(array $amounts): array
    {
        $room = $this->room();
        $category = $room->getRoomCategory();
        self::assertNotNull($category);
        $occupancies = static::getContainer()->get(PriceRepository::class)->findOccupanciesForRoomCategory($category);
        self::assertNotEmpty($occupancies, 'Sample data must contain a room price for the first room.');

        $nights = [];
        foreach ($amounts as $offset => $amount) {
            $nights[] = ['night' => (new \DateTimeImmutable($offset))->format('Y-m-d'), 'amount' => $amount];
        }

        return [
            'objectId' => (int) $room->getObject()?->getId(),
            'roomCategoryId' => (int) $category->getId(),
            'persons' => $occupancies[0],
            'nights' => $nights,
        ];
    }

    /**
     * The rate of the night for the branch, room category and persons of the body.
     *
     * @param array{objectId: int, roomCategoryId: int, persons: int} $body
     *
     * @return array<string, mixed>
     */
    private function rate(string $token, array $body, string $night): array
    {
        // Day prices are stated for the origin of online bookings, here the first origin.
        $origin = static::getContainer()->get(DayPriceResolver::class)->referenceOrigin() ?? self::fail('Sample data must contain an origin.');
        $this->client->request('GET', sprintf('/api/v1/prices/rates?roomCategoryId=%d&objectId=%d&start=%s&occupancy=%d&originId=%d', $body['roomCategoryId'], $body['objectId'], $night, $body['persons'], $origin->getId()), [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $rates = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($body['objectId'], $rates['meta']['objectId']);

        return $rates['data'][0]['rates'][0] ?? self::fail('The night has no rate.');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function put(string $token, array $body): array
    {
        $this->client->request('PUT', '/api/v1/day-prices', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode($body));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array<string, array{0: float, 1: DayPriceSource}> */
    private function storedDayPrices(): array
    {
        $this->em()->clear();
        $stored = [];
        foreach ($this->em()->getRepository(DayPrice::class)->findBy([], ['night' => 'ASC']) as $dayPrice) {
            $stored[$dayPrice->getNight()->format('Y-m-d')] = [$dayPrice->getAmount(), $dayPrice->getSource()];
        }

        return $stored;
    }

    private function room(): Appartment
    {
        $room = $this->em()->getRepository(Appartment::class)->findOneBy(['active' => true], ['id' => 'ASC']);
        self::assertInstanceOf(Appartment::class, $room);

        return $room;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param list<string>   $roleCodes
     * @param list<ApiScope> $scopes
     *
     * @return array{0: User, 1: string}
     */
    private function createUserWithToken(array $roleCodes, array $scopes): array
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
        $roles = [];
        foreach ($roleCodes as $roleCode) {
            $roles[] = $em->getRepository(Role::class)->findOneBy(['role' => $roleCode])
                ?? self::fail(sprintf('Role %s must exist in database.', $roleCode));
        }
        $user->setRoleEntities($roles);
        $em->persist($user);
        $em->flush();

        $result = $container->get(ApiTokenService::class)->createToken(
            $user,
            'functional-test',
            array_map(static fn (ApiScope $scope): string => $scope->value, $scopes),
            null,
        );

        return [$user, $result->plainToken];
    }
}
