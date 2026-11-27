<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\Enum\ApiScope;
use App\Entity\Enum\RoomCategoryKind;
use App\Entity\Role;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Entity\User;
use App\Service\ApiTokenService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ApiApartmentsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->connection = $this->em()->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testOnlyTokensWithTheRoomScopeAndReservationRoleCanReadRooms(): void
    {
        $this->request();
        self::assertResponseStatusCodeSame(401);

        $reservationToken = $this->createToken(ApiScope::RESERVATIONS_READ);
        $this->request($reservationToken);
        self::assertResponseStatusCodeSame(403);

        $customerToken = $this->createToken(ApiScope::APARTMENTS_READ, 'ROLE_CUSTOMERS');
        $this->request($customerToken);
        self::assertResponseStatusCodeSame(403);
    }

    public function testListFiltersByBranchAndKeepsDuplicateNumbersAndInactiveRooms(): void
    {
        $token = $this->createToken(ApiScope::APARTMENTS_READ);
        $branch = $this->createBranch('Main house');
        $otherBranch = $this->createBranch('Annex');
        $category = new RoomCategory()->setName('Seminar room')->setKind(RoomCategoryKind::EVENT_SPACE);
        $this->em()->persist($category);
        $hall = $this->createRoom('007', 'Garden hall', $branch);
        $hall->setRoomCategory($category);
        $oldRoom = $this->createRoom('007', 'Garden room', $branch)->setActive(false);
        $otherRoom = $this->createRoom('007', 'Garden room', $otherBranch);
        $legacyRoom = $this->createRoom('002', 'Legacy room');
        $this->em()->flush();

        $this->request($token, '?objectId='.$branch->getId());
        self::assertResponseIsSuccessful();
        $data = $this->payload();
        self::assertSame(['count' => 2], $data['meta']);
        self::assertSame([
            [
                'id' => $hall->getId(),
                'number' => '007',
                'description' => 'Garden hall',
                'objectId' => $branch->getId(),
                'roomCategoryId' => $category->getId(),
                'kind' => 'event_space',
                'active' => true,
            ],
            [
                'id' => $oldRoom->getId(),
                'number' => '007',
                'description' => 'Garden room',
                'objectId' => $branch->getId(),
                'roomCategoryId' => null,
                'kind' => 'accommodation',
                'active' => false,
            ],
        ], $data['data']);

        $this->request($token);
        self::assertResponseIsSuccessful();
        $data = $this->payload();
        $rooms = array_column($data['data'], null, 'id');
        self::assertSame(\count($rooms), $data['meta']['count']);
        self::assertSame($otherBranch->getId(), $rooms[$otherRoom->getId()]['objectId']);
        self::assertNull($rooms[$legacyRoom->getId()]['objectId']);
        self::assertNull($rooms[$legacyRoom->getId()]['roomCategoryId']);
    }

    public function testExistingBranchWithoutRoomsReturnsAnEmptyList(): void
    {
        $token = $this->createToken(ApiScope::APARTMENTS_READ);
        $branch = $this->createBranch('Empty branch');
        $this->em()->flush();

        $this->request($token, '?objectId='.$branch->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(['data' => [], 'meta' => ['count' => 0]], $this->payload());
    }

    public function testInvalidOrUnknownObjectIdReturnsBadRequest(): void
    {
        $token = $this->createToken(ApiScope::APARTMENTS_READ);
        $branch = $this->createBranch('A branch');
        $this->em()->flush();

        foreach (['?objectId=', '?objectId=0', '?objectId='.$branch->getId().'x', '?objectId[]=1', '?objectId=2147483647'] as $query) {
            $this->request($token, $query);

            self::assertResponseStatusCodeSame(400);
            self::assertSame(400, $this->payload()['error']['code']);
        }
    }

    private function request(?string $token = null, string $query = ''): void
    {
        $server = null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        $this->client->request('GET', '/api/v1/apartments'.$query, [], [], $server);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function createBranch(string $name): Subsidiary
    {
        $branch = new Subsidiary();
        $branch->setName($name);
        $branch->setDescription('API apartment test');
        $this->em()->persist($branch);

        return $branch;
    }

    private function createRoom(string $number, string $description, ?Subsidiary $branch = null): Appartment
    {
        $room = new Appartment();
        $room->setNumber($number);
        $room->setDescription($description);
        $room->setBedsMax(2);
        if (null !== $branch) {
            $room->setObject($branch);
        }
        $this->em()->persist($room);

        return $room;
    }

    private function createToken(ApiScope $scope, string $roleCode = 'ROLE_RESERVATIONS_RO'): string
    {
        $container = static::getContainer();
        $em = $this->em();
        $role = $em->getRepository(Role::class)->findOneBy(['role' => $roleCode]);
        self::assertInstanceOf(Role::class, $role);

        $suffix = bin2hex(random_bytes(6));
        $user = new User();
        $user->setUsername('api_'.$suffix);
        $user->setFirstname('Api');
        $user->setLastname('Tester');
        $user->setEmail('api+'.$suffix.'@example.com');
        $user->setActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'ChangeMe123!'));
        $user->setRoleEntities([$role]);
        $em->persist($user);
        $em->flush();

        return $container->get(ApiTokenService::class)->createToken($user, 'functional-test', [$scope->value], null)->plainToken;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
