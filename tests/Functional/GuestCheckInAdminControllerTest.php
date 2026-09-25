<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AppSettings;
use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\Enum\GuestCheckInStatus;
use App\Entity\GuestCheckIn;
use App\Entity\GuestCheckInConfig;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/** Staff side of the online check-in in the reservation dialog. */
final class GuestCheckInAdminControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private ?int $reservationId = null;
    /** @var list<int> */
    private array $customerIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $config = $this->em()->getRepository(GuestCheckInConfig::class)->findOneBy([]) ?? throw new \RuntimeException('Config row missing.');
        $config->setEnabled(true);
        $this->em()->getRepository(AppSettings::class)->findOneBy([])?->setPublicBaseUrl('https://fewohbee.example.com');
        $this->em()->flush();
    }

    protected function tearDown(): void
    {
        $connection = $this->em()->getConnection();
        if (null !== $this->reservationId) {
            $ids = $connection->fetchFirstColumn('SELECT customer_id FROM reservations_has_customers WHERE reservation_id = ?', [$this->reservationId]);
            $ids = [...$ids, ...$connection->fetchFirstColumn('SELECT booker_id FROM reservations WHERE id = ? AND booker_id IS NOT NULL', [$this->reservationId])];
            $this->customerIds = array_values(array_unique([...$this->customerIds, ...array_map('intval', $ids)]));
            $connection->executeStatement('DELETE FROM guest_check_in WHERE reservation_id = ?', [$this->reservationId]);
            $connection->executeStatement('DELETE FROM reservations_has_customers WHERE reservation_id = ?', [$this->reservationId]);
            $connection->executeStatement('DELETE FROM reservations WHERE id = ?', [$this->reservationId]);
        }
        foreach ($this->customerIds as $id) {
            $addressIds = $connection->fetchFirstColumn('SELECT customer_addresses_id FROM customer_has_address WHERE customer_id = ?', [$id]);
            $connection->executeStatement('DELETE FROM customer_has_address WHERE customer_id = ?', [$id]);
            foreach ($addressIds as $addressId) {
                $connection->executeStatement('DELETE FROM customer_addresses WHERE id = ? AND id NOT IN (SELECT customer_addresses_id FROM customer_has_address)', [$addressId]);
            }
            $connection->executeStatement('DELETE FROM customers WHERE id = ?', [$id]);
        }
        $connection->executeStatement('UPDATE guest_check_in_config SET enabled = 0');
        $connection->executeStatement('UPDATE app_settings SET public_base_url = NULL');
        parent::tearDown();
    }

    public function testReadOnlyUserSeesTheTabButCannotGetTheLink(): void
    {
        $reservation = $this->createReservation();
        $this->client->loginUser($this->userWithRole('ROLE_RESERVATIONS_RO'));

        $this->client->request('GET', '/reservation/get/'.$reservation->getId(), ['tab' => 'checkin']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#guest-checkin');
        self::assertSelectorNotExists('[data-action="guest-checkin#showLink"]');

        $this->client->request('POST', '/reservation/'.$reservation->getId().'/checkin/link', ['_token' => 'anything']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testLinkNeedsAValidToken(): void
    {
        $reservation = $this->createReservation();
        $this->loginAdmin();

        $this->client->request('POST', '/reservation/'.$reservation->getId().'/checkin/link', ['_token' => 'invalid']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testNewLinkRevokesTheOldOne(): void
    {
        $reservation = $this->createReservation();
        $this->loginAdmin();
        $token = $this->tabToken($reservation);

        $this->client->request('POST', '/reservation/'.$reservation->getId().'/checkin/link', ['_token' => $token]);
        self::assertResponseIsSuccessful();
        $first = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringStartsWith('https://fewohbee.example.com/checkin/', $first['url']);
        self::assertStringStartsWith('data:image/png;base64,', $first['qr']);

        $this->client->request('POST', '/reservation/'.$reservation->getId().'/checkin/regenerate', ['_token' => $token]);
        $second = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNotSame($first['url'], $second['url']);

        $this->client->request('GET', (string) parse_url($first['url'], \PHP_URL_PATH));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', (string) parse_url($second['url'], \PHP_URL_PATH));
        self::assertResponseIsSuccessful();
    }

    public function testTakingOverCreatesTheGuestsAndDropsTheSubmission(): void
    {
        $reservation = $this->createReservation();
        $this->submit($reservation);
        $this->loginAdmin();
        $token = $this->tabToken($reservation);

        $this->client->request('POST', '/reservation/'.$reservation->getId().'/checkin/apply', [
            '_token' => $token,
            'mainTarget' => 'booker',
            'companionTargets' => ['new'],
        ]);

        self::assertResponseIsSuccessful();
        $this->em()->clear();
        $stored = $this->em()->find(Reservation::class, $reservation->getId());
        self::assertNotNull($stored);
        self::assertSame('AT', $stored->getBooker()?->getNationality());
        $names = array_map(static fn (Customer $c): string => (string) $c->getFirstname(), $stored->getCustomers()->toArray());
        sort($names);
        self::assertSame(['Anna', 'Max'], $names);
        $checkIn = $this->checkIn($reservation);
        self::assertSame(GuestCheckInStatus::APPLIED, $checkIn->getStatus());
        self::assertFalse($checkIn->hasPayload());
    }

    public function testTargetOutsideTheReservationChangesNothing(): void
    {
        $reservation = $this->createReservation();
        $this->submit($reservation);
        $this->loginAdmin();
        $token = $this->tabToken($reservation);
        $foreign = $this->em()->getRepository(Customer::class)->findOneBy([]);

        $this->client->request('POST', '/reservation/'.$reservation->getId().'/checkin/apply', [
            '_token' => $token,
            'mainTarget' => 'customer:'.$foreign?->getId(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(GuestCheckInStatus::SUBMITTED, $this->checkIn($reservation)->getStatus());
    }

    public function testDiscardLetsTheGuestStartOver(): void
    {
        $reservation = $this->createReservation();
        $this->submit($reservation);
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/reservation/get/'.$reservation->getId(), ['tab' => 'checkin']);
        $popover = $crawler->filter('[data-popover="delete"][data-delete-target]')->attr('data-bs-content');
        preg_match('/name="_token" value="([^"]+)"/', (string) $popover, $match);

        $this->client->request('DELETE', '/reservation/'.$reservation->getId().'/checkin/discard', ['_token' => $match[1] ?? '']);

        self::assertResponseIsSuccessful();
        $checkIn = $this->checkIn($reservation);
        self::assertSame(GuestCheckInStatus::OPEN, $checkIn->getStatus());
        self::assertFalse($checkIn->hasPayload());
    }

    public function testFrontdeskShowsArrivalTimeAndCheckInState(): void
    {
        $reservation = $this->createReservation();
        $reservation->setArrivalTime(new \DateTime('18:30'));
        $this->em()->flush();
        $this->submit($reservation);
        $this->loginAdmin();

        $this->client->request('GET', '/operations/frontdesk', ['date' => $reservation->getStartDate()->format('Y-m-d'), 'subsidiary' => 'all']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', '18:30');
        self::assertSelectorExists('table .badge .fa-id-card');
    }

    private function submit(Reservation $reservation): void
    {
        $checkIn = new GuestCheckIn($reservation, 'Sel'.bin2hex(random_bytes(9)).'x');
        $checkIn->recordSubmission([
            'v' => 1,
            'arrivalTime' => '18:00',
            'mainGuest' => ['salutation' => 'Ms', 'firstname' => 'Anna', 'lastname' => 'Müller', 'nationality' => 'AT', 'address' => ['street' => 'Ring 1', 'zip' => '1010', 'city' => 'Wien', 'country' => 'AT']],
            'companions' => [['firstname' => 'Max', 'lastname' => 'Müller', 'birthday' => '2015-03-03', 'nationality' => 'AT']],
        ], new \DateTimeImmutable());
        $this->em()->persist($checkIn);
        $this->em()->flush();
    }

    private function tabToken(Reservation $reservation): string
    {
        $crawler = $this->client->request('GET', '/reservation/get/'.$reservation->getId(), ['tab' => 'checkin']);
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('[data-controller="guest-checkin"]')->attr('data-guest-checkin-token-value');
    }

    private function checkIn(Reservation $reservation): GuestCheckIn
    {
        $this->em()->clear();

        return $this->em()->getRepository(GuestCheckIn::class)->findOneBy(['reservation' => $reservation->getId()])
            ?? throw new \RuntimeException('Check-in row missing.');
    }

    private function loginAdmin(): void
    {
        $admin = $this->em()->getRepository(User::class)->findOneBy(['username' => 'test-admin']) ?? throw new \RuntimeException('Prepared test admin missing.');
        $this->client->loginUser($admin, 'main');
    }

    private function userWithRole(string $roleCode): User
    {
        $em = $this->em();
        $user = new User();
        $user->setUsername('test_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('User');
        $user->setEmail(sprintf('test+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'ChangeMe123!'));
        $user->setRoleEntities([$em->getRepository(Role::class)->findOneBy(['role' => $roleCode]) ?? throw new \RuntimeException('Role missing.')]);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createReservation(): Reservation
    {
        $em = $this->em();
        $booker = new Customer();
        $booker->setSalutation('Frau');
        $booker->setFirstname('Anna');
        $booker->setLastname('Müller');
        $em->persist($booker);

        $reservation = new Reservation();
        $reservation->setReservationOrigin($em->getRepository(ReservationOrigin::class)->findOneBy([]));
        $reservation->setReservationStatus($em->getRepository(ReservationStatus::class)->findOneBy(['isBlocking' => true]));
        $reservation->setPersons(2);
        $reservation->setStartDate(new \DateTime('+10 days'));
        $reservation->setEndDate(new \DateTime('+12 days'));
        $reservation->setAppartment($em->getRepository(Appartment::class)->findOneBy([]));
        $reservation->setReservationDate(new \DateTime());
        $reservation->setIsConflict(false);
        $reservation->setIsConflictIgnored(false);
        $reservation->setUuid(Uuid::v4());
        $reservation->setBooker($booker);
        $em->persist($reservation);
        $em->flush();

        $this->reservationId = $reservation->getId();
        $this->customerIds[] = (int) $booker->getId();

        return $reservation;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
