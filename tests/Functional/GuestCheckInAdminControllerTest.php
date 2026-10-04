<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AppSettings;
use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
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
        $reservation->setRemark('Existing staff note');
        $this->submit($reservation, 'We arrive by train.');
        $this->loginAdmin();
        $token = $this->tabToken($reservation);

        $this->client->request('POST', '/reservation/'.$reservation->getId().'/checkin/apply', [
            '_token' => $token,
            'submissionVersion' => $this->checkIn($reservation)->getSubmissionVersion(),
            'mainTarget' => 'booker',
            'companionTargets' => ['new'],
        ]);

        self::assertResponseIsSuccessful();
        $this->em()->clear();
        $stored = $this->em()->find(Reservation::class, $reservation->getId());
        self::assertNotNull($stored);
        self::assertSame('AT', $stored->getBooker()?->getNationality());
        self::assertStringStartsWith("Existing staff note\n\nKommentar vom Gast am ", (string) $stored->getRemark());
        self::assertStringContainsString("\nWe arrive by train.", (string) $stored->getRemark());
        $names = array_map(static fn (Customer $c): string => (string) $c->getFirstname(), $stored->getCustomers()->toArray());
        sort($names);
        self::assertSame(['Anna', 'Max'], $names);
        $checkIn = $this->checkIn($reservation);
        self::assertSame(GuestCheckInStatus::APPLIED, $checkIn->getStatus());
        self::assertFalse($checkIn->hasPayload());
    }

    public function testBookerTargetDistinguishesUnchangedFromUpdatedGuestData(): void
    {
        $reservation = $this->createReservation();
        $this->submit($reservation);
        $checkIn = $this->checkIn($reservation);
        $payload = $checkIn->getPayload();
        self::assertIsArray($payload);
        $payload['mainGuest'] = ['firstname' => 'Anna', 'lastname' => 'Müller'];
        $payload['companions'] = [];
        $checkIn->recordSubmission($payload, new \DateTimeImmutable());
        $this->em()->flush();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/reservation/get/'.$reservation->getId(), ['tab' => 'checkin']);
        self::assertResponseIsSuccessful();
        self::assertSame('Bucher: Anna Müller (keine Aktualisierung nötig)', trim($crawler->filter('select[name="mainTarget"] option[value="booker"]')->text()));

        $checkIn = $this->checkIn($reservation);
        $payload = $checkIn->getPayload();
        self::assertIsArray($payload);
        $payload['mainGuest']['nationality'] = 'AT';
        $checkIn->recordSubmission($payload, new \DateTimeImmutable());
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/reservation/get/'.$reservation->getId(), ['tab' => 'checkin']);
        self::assertResponseIsSuccessful();
        self::assertSame('Bucher aktualisieren (Anna Müller)', trim($crawler->filter('select[name="mainTarget"] option[value="booker"]')->text()));
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

    public function testSubmissionChangedDuringReviewCannotBeAppliedEvenAtSameTimestamp(): void
    {
        $reservation = $this->createReservation();
        $this->submit($reservation);
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/reservation/get/'.$reservation->getId(), ['tab' => 'checkin']);
        $token = (string) $crawler->filter('[data-controller="guest-checkin"]')->attr('data-guest-checkin-token-value');
        $version = (string) $crawler->filter('input[name="submissionVersion"]')->attr('value');

        $checkIn = $this->checkIn($reservation);
        $payload = $checkIn->getPayload();
        self::assertIsArray($payload);
        $payload['mainGuest']['nationality'] = 'CH';
        $submittedAt = $checkIn->getLastSubmittedAt();
        self::assertInstanceOf(\DateTimeImmutable::class, $submittedAt);
        $checkIn->recordSubmission($payload, $submittedAt);
        $this->em()->flush();

        $this->client->request('POST', '/reservation/'.$reservation->getId().'/checkin/apply', [
            '_token' => $token,
            'submissionVersion' => $version,
            'mainTarget' => 'booker',
        ]);

        self::assertSame(GuestCheckInStatus::SUBMITTED, $this->checkIn($reservation)->getStatus());
        self::assertNull($this->em()->find(Reservation::class, $reservation->getId())?->getBooker()?->getNationality());
    }

    public function testDifferentMainGuestDefaultsToNewAndCannotOverwriteBookerWithoutConfirmation(): void
    {
        $reservation = $this->createReservation();
        $this->submit($reservation);
        $checkIn = $this->checkIn($reservation);
        $payload = $checkIn->getPayload();
        self::assertIsArray($payload);
        $payload['mainGuest']['firstname'] = 'Lea';
        $payload['mainGuest']['lastname'] = 'Novak';
        $payload['companions'] = [];
        $checkIn->recordSubmission($payload, new \DateTimeImmutable());
        $this->em()->flush();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/reservation/get/'.$reservation->getId(), ['tab' => 'checkin']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="mainTarget"] option[value="new"][selected]');
        self::assertSelectorExists('input[name="confirmBookerMismatch"]');
        self::assertSelectorExists('[data-guest-checkin-target="bookerConfirmation"].d-none input[name="confirmBookerMismatch"][disabled]');
        $token = (string) $crawler->filter('[data-controller="guest-checkin"]')->attr('data-guest-checkin-token-value');
        $submissionVersion = (string) $crawler->filter('input[name="submissionVersion"]')->attr('value');

        $this->client->request('POST', '/reservation/'.$reservation->getId().'/checkin/apply', [
            '_token' => $token,
            'submissionVersion' => $submissionVersion,
            'mainTarget' => 'booker',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(GuestCheckInStatus::SUBMITTED, $this->checkIn($reservation)->getStatus());
        self::assertSame('Anna', $this->em()->find(Reservation::class, $reservation->getId())?->getBooker()?->getFirstname());

        $this->client->request('POST', '/reservation/'.$reservation->getId().'/checkin/apply', [
            '_token' => $token,
            'submissionVersion' => $submissionVersion,
            'mainTarget' => 'new',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(GuestCheckInStatus::APPLIED, $this->checkIn($reservation)->getStatus());
        $this->em()->clear();
        $stored = $this->em()->find(Reservation::class, $reservation->getId());
        self::assertInstanceOf(Reservation::class, $stored);
        self::assertSame('Anna', $stored->getBooker()?->getFirstname());
        self::assertSame(['Lea'], array_map(static fn (Customer $guest): ?string => $guest->getFirstname(), $stored->getCustomers()->toArray()));
    }

    public function testMatchingLinkedGuestIsSuggestedInsteadOfBooker(): void
    {
        $reservation = $this->createReservation();
        $guest = new Customer();
        $guest->setSalutation('');
        $guest->setFirstname('Lea');
        $guest->setLastname('Novak');
        $this->em()->persist($guest);
        $reservation->addCustomer($guest);
        $this->em()->flush();
        $this->submit($reservation);
        $checkIn = $this->checkIn($reservation);
        $payload = $checkIn->getPayload();
        self::assertIsArray($payload);
        $payload['mainGuest']['firstname'] = 'Lea';
        $payload['mainGuest']['lastname'] = 'Novak';
        $checkIn->recordSubmission($payload, new \DateTimeImmutable());
        $this->em()->flush();
        $this->loginAdmin();

        $this->client->request('GET', '/reservation/get/'.$reservation->getId(), ['tab' => 'checkin']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="mainTarget"] option[value="customer:'.$guest->getId().'"][selected]');
        self::assertStringContainsString('Bisher bei Lea Novak', (string) $this->client->getResponse()->getContent());
    }

    public function testMatchingGuestsFromOtherReservationsAreReusedForMainAndCompanion(): void
    {
        $reservation = $this->createReservation();
        $main = $this->createStoredGuest('Lea', 'Fernau', '1990-01-01', 'lea.fernau@example.com');
        $companion = $this->createStoredGuest('Max', 'Fernau', '2015-03-03');
        $this->submit($reservation);
        $checkIn = $this->checkIn($reservation);
        $payload = $checkIn->getPayload();
        self::assertIsArray($payload);
        $payload['mainGuest'] = [
            'firstname' => 'Lea', 'lastname' => 'Fernau', 'birthday' => '1990-01-01',
            'email' => 'lea.fernau@example.com', 'nationality' => 'AT',
        ];
        $payload['companions'] = [['firstname' => 'Max', 'lastname' => 'Fernau', 'birthday' => '2015-03-03']];
        $checkIn->recordSubmission($payload, new \DateTimeImmutable());
        $this->em()->flush();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/reservation/get/'.$reservation->getId(), ['tab' => 'checkin']);
        self::assertSelectorExists('select[name="mainTarget"] option[value="existing:'.$main->getId().'"][selected]');
        self::assertSelectorExists('select[name="companionTargets[]"] option[value="existing:'.$companion->getId().'"][selected]');
        self::assertSame('Vorhandener Gast: Lea Fernau (Nr. '.$main->getId().')', trim($crawler->filter('select[name="mainTarget"] option[value="existing:'.$main->getId().'"]')->text()));
        self::assertSame('Vorhandener Gast: Max Fernau (Nr. '.$companion->getId().')', trim($crawler->filter('select[name="companionTargets[]"] option[value="existing:'.$companion->getId().'"]')->text()));
        $token = (string) $crawler->filter('[data-controller="guest-checkin"]')->attr('data-guest-checkin-token-value');
        $version = (string) $crawler->filter('input[name="submissionVersion"]')->attr('value');

        $this->client->request('POST', '/reservation/'.$reservation->getId().'/checkin/apply', [
            '_token' => $token,
            'submissionVersion' => $version,
            'mainTarget' => 'existing:'.$main->getId(),
            'companionTargets' => ['existing:'.$companion->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $this->em()->clear();
        $stored = $this->em()->find(Reservation::class, $reservation->getId());
        self::assertInstanceOf(Reservation::class, $stored);
        $guestIds = array_map(static fn (Customer $guest): ?int => $guest->getId(), $stored->getCustomers()->toArray());
        sort($guestIds);
        $expectedIds = [$main->getId(), $companion->getId()];
        sort($expectedIds);
        self::assertSame($expectedIds, $guestIds);
        self::assertSame('Anna', $stored->getBooker()?->getFirstname());
        self::assertSame(GuestCheckInStatus::APPLIED, $this->checkIn($reservation)->getStatus());
    }

    public function testUniqueSameNameCompanionWithoutStoredBirthdayNeedsManualChoice(): void
    {
        $reservation = $this->createReservation();
        $existing = $this->createStoredGuest('Maxi', 'Musterfrau');
        $this->submit($reservation);
        $checkIn = $this->checkIn($reservation);
        $payload = $checkIn->getPayload();
        self::assertIsArray($payload);
        $payload['companions'] = [[
            'salutation' => 'Ms', 'firstname' => 'Maxi', 'lastname' => 'Musterfrau', 'birthday' => '1995-06-15',
        ]];
        $checkIn->recordSubmission($payload, new \DateTimeImmutable());
        $this->em()->flush();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/reservation/get/'.$reservation->getId(), ['tab' => 'checkin']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="companionTargets[]"] option[value=""][selected][disabled]');
        self::assertSelectorExists('select[name="companionTargets[]"] option[value="existing:'.$existing->getId().'"]');
        self::assertStringContainsString('Ein Gast mit diesem Namen ist vorhanden', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Frau Maxi Musterfrau', (string) $this->client->getResponse()->getContent());
        $token = (string) $crawler->filter('[data-controller="guest-checkin"]')->attr('data-guest-checkin-token-value');
        $version = (string) $crawler->filter('input[name="submissionVersion"]')->attr('value');

        $this->client->request('POST', '/reservation/'.$reservation->getId().'/checkin/apply', [
            '_token' => $token,
            'submissionVersion' => $version,
            'mainTarget' => 'booker',
            'companionTargets' => ['existing:'.$existing->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $this->em()->clear();
        $stored = $this->em()->find(Reservation::class, $reservation->getId());
        self::assertInstanceOf(Reservation::class, $stored);
        $guestIds = array_map(static fn (Customer $guest): ?int => $guest->getId(), $stored->getCustomers()->toArray());
        self::assertCount(2, $guestIds);
        self::assertContains($existing->getId(), $guestIds);
        $matched = $this->em()->find(Customer::class, $existing->getId());
        self::assertInstanceOf(Customer::class, $matched);
        self::assertSame('1995-06-15', $matched->getBirthday()?->format('Y-m-d'));
        self::assertSame('Frau', $matched->getSalutation());
    }

    public function testUniqueSameNameMainGuestWithoutStoredBirthdayIsOfferedButNotSelected(): void
    {
        $reservation = $this->createReservation();
        $existing = $this->createStoredGuest('Lea', 'Fernau');
        $this->submit($reservation);
        $checkIn = $this->checkIn($reservation);
        $payload = $checkIn->getPayload();
        self::assertIsArray($payload);
        $payload['mainGuest'] = ['firstname' => 'Lea', 'lastname' => 'Fernau', 'birthday' => '1990-01-01'];
        $payload['companions'] = [];
        $checkIn->recordSubmission($payload, new \DateTimeImmutable());
        $this->em()->flush();
        $this->loginAdmin();

        $this->client->request('GET', '/reservation/get/'.$reservation->getId(), ['tab' => 'checkin']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="mainTarget"] option[value=""][selected][disabled]');
        self::assertSelectorExists('select[name="mainTarget"] option[value="existing:'.$existing->getId().'"]');
        self::assertStringContainsString('Ein Gast mit diesem Namen ist vorhanden', (string) $this->client->getResponse()->getContent());
    }

    public function testAmbiguousOrUnrelatedGlobalGuestsAreNotChosenAutomaticallyOrByForgedId(): void
    {
        $reservation = $this->createReservation();
        $first = $this->createStoredGuest('Lea', 'Fernau', '1990-01-01');
        $second = $this->createStoredGuest('Lea', 'Fernau', '1990-01-01');
        $wrongBirthday = $this->createStoredGuest('Lea', 'Fernau', '1980-01-01', 'lea@example.com');
        $this->submit($reservation);
        $checkIn = $this->checkIn($reservation);
        $payload = $checkIn->getPayload();
        self::assertIsArray($payload);
        $payload['mainGuest'] = ['firstname' => 'Lea', 'lastname' => 'Fernau', 'birthday' => '1990-01-01', 'email' => 'lea@example.com'];
        $payload['companions'] = [];
        $checkIn->recordSubmission($payload, new \DateTimeImmutable());
        $this->em()->flush();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/reservation/get/'.$reservation->getId(), ['tab' => 'checkin']);
        self::assertSelectorExists('select[name="mainTarget"] option[value=""][selected][disabled]');
        self::assertSelectorExists('select[name="mainTarget"] option[value="existing:'.$first->getId().'"]');
        self::assertSelectorExists('select[name="mainTarget"] option[value="existing:'.$second->getId().'"]');
        self::assertSelectorNotExists('select[name="mainTarget"] option[value="existing:'.$wrongBirthday->getId().'"]');
        self::assertStringContainsString('mehrere passende Datensätze', (string) $this->client->getResponse()->getContent());
        $token = (string) $crawler->filter('[data-controller="guest-checkin"]')->attr('data-guest-checkin-token-value');
        $version = (string) $crawler->filter('input[name="submissionVersion"]')->attr('value');

        $this->client->request('POST', '/reservation/'.$reservation->getId().'/checkin/apply', [
            '_token' => $token, 'submissionVersion' => $version, 'mainTarget' => 'existing:'.$wrongBirthday->getId(),
        ]);
        self::assertSame(GuestCheckInStatus::SUBMITTED, $this->checkIn($reservation)->getStatus());
        self::assertSame('1980-01-01', $this->em()->find(Customer::class, $wrongBirthday->getId())?->getBirthday()?->format('Y-m-d'));

        $this->client->loginUser($this->userWithRole('ROLE_RESERVATIONS'), 'main');
        $restrictedCrawler = $this->client->request('GET', '/reservation/get/'.$reservation->getId(), ['tab' => 'checkin']);
        self::assertSelectorNotExists('select[name="mainTarget"] option[value^="existing:"]');
        $restrictedToken = (string) $restrictedCrawler->filter('[data-controller="guest-checkin"]')->attr('data-guest-checkin-token-value');
        $this->client->request('POST', '/reservation/'.$reservation->getId().'/checkin/apply', [
            '_token' => $restrictedToken, 'submissionVersion' => $version, 'mainTarget' => 'existing:'.$first->getId(),
        ]);
        self::assertSame(GuestCheckInStatus::SUBMITTED, $this->checkIn($reservation)->getStatus());

        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/reservation/get/'.$reservation->getId(), ['tab' => 'checkin']);
        $adminToken = (string) $crawler->filter('[data-controller="guest-checkin"]')->attr('data-guest-checkin-token-value');
        $this->client->request('POST', '/reservation/'.$reservation->getId().'/checkin/apply', [
            '_token' => $adminToken, 'submissionVersion' => $version, 'mainTarget' => 'existing:'.$first->getId(),
        ]);
        self::assertSame(GuestCheckInStatus::APPLIED, $this->checkIn($reservation)->getStatus());
        $stored = $this->em()->find(Reservation::class, $reservation->getId());
        self::assertInstanceOf(Reservation::class, $stored);
        self::assertSame([$first->getId()], array_map(static fn (Customer $guest): ?int => $guest->getId(), $stored->getCustomers()->toArray()));
    }

    public function testEmailCanIdentifySameNameGuestButNameAloneCannot(): void
    {
        $reservation = $this->createReservation();
        $matching = $this->createStoredGuest('Lea', 'Fernau', null, 'lea@example.com');
        $other = $this->createStoredGuest('Lea', 'Fernau', null, 'other@example.com');
        $this->submit($reservation);
        $checkIn = $this->checkIn($reservation);
        $payload = $checkIn->getPayload();
        self::assertIsArray($payload);
        $payload['mainGuest'] = ['firstname' => 'Lea', 'lastname' => 'Fernau', 'email' => 'LEA@example.com'];
        $payload['companions'] = [];
        $checkIn->recordSubmission($payload, new \DateTimeImmutable());
        $this->em()->flush();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/reservation/get/'.$reservation->getId(), ['tab' => 'checkin']);
        self::assertSelectorExists('select[name="mainTarget"] option[value="existing:'.$matching->getId().'"][selected]');
        self::assertSelectorNotExists('select[name="mainTarget"] option[value="existing:'.$other->getId().'"]');
        self::assertSame('Vorhandener Gast: Lea Fernau (Nr. '.$matching->getId().')', trim($crawler->filter('select[name="mainTarget"] option[value="existing:'.$matching->getId().'"]')->text()));

        $storedMatch = $this->em()->find(Customer::class, $matching->getId());
        self::assertInstanceOf(Customer::class, $storedMatch);
        $address = $storedMatch->getCustomerAddresses()->first();
        self::assertInstanceOf(CustomerAddresses::class, $address);
        $address->setEmail('changed@example.com');
        $this->em()->flush();
        self::assertSame('changed@example.com', $this->em()->getConnection()->fetchOne('SELECT email FROM customer_addresses WHERE id = ?', [$address->getId()]));
        $token = (string) $crawler->filter('[data-controller="guest-checkin"]')->attr('data-guest-checkin-token-value');
        $version = (string) $crawler->filter('input[name="submissionVersion"]')->attr('value');
        $this->client->request('POST', '/reservation/'.$reservation->getId().'/checkin/apply', [
            '_token' => $token, 'submissionVersion' => $version, 'mainTarget' => 'existing:'.$matching->getId(),
        ]);
        self::assertSame(GuestCheckInStatus::SUBMITTED, $this->checkIn($reservation)->getStatus());

        unset($payload['mainGuest']['email']);
        $checkIn = $this->checkIn($reservation);
        $checkIn->recordSubmission($payload, new \DateTimeImmutable());
        $this->em()->flush();
        $this->client->request('GET', '/reservation/get/'.$reservation->getId(), ['tab' => 'checkin']);
        self::assertSelectorExists('select[name="mainTarget"] option[value="new"][selected]');
        self::assertSelectorNotExists('select[name="mainTarget"] option[value^="existing:"]');
    }

    public function testDifferentSoloMainGuestNeedsRoomPlaceAndCanLeaveBookerAsBooker(): void
    {
        $reservation = $this->createReservation();
        $reservation->setPersons(1);
        $booker = $reservation->getBooker();
        self::assertInstanceOf(Customer::class, $booker);
        $reservation->addCustomer($booker);
        $this->em()->flush();
        $this->submit($reservation);
        $checkIn = $this->checkIn($reservation);
        $payload = $checkIn->getPayload();
        self::assertIsArray($payload);
        $payload['mainGuest']['firstname'] = 'Lea';
        $payload['mainGuest']['lastname'] = 'Novak';
        $payload['companions'] = [];
        $checkIn->recordSubmission($payload, new \DateTimeImmutable());
        $this->em()->flush();
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/reservation/get/'.$reservation->getId(), ['tab' => 'checkin']);
        self::assertSelectorExists('input[name="removeBookerFromGuests"]');
        $token = (string) $crawler->filter('[data-controller="guest-checkin"]')->attr('data-guest-checkin-token-value');
        $version = (string) $crawler->filter('input[name="submissionVersion"]')->attr('value');
        $request = ['_token' => $token, 'submissionVersion' => $version, 'mainTarget' => 'new'];

        $this->client->request('POST', '/reservation/'.$reservation->getId().'/checkin/apply', $request);
        self::assertStringContainsString('alle Plätze belegt', (string) $this->client->getResponse()->getContent());
        self::assertSame(GuestCheckInStatus::SUBMITTED, $this->checkIn($reservation)->getStatus());

        $this->client->request('POST', '/reservation/'.$reservation->getId().'/checkin/apply', $request + ['removeBookerFromGuests' => '1']);
        self::assertResponseIsSuccessful();
        $this->em()->clear();
        $stored = $this->em()->find(Reservation::class, $reservation->getId());
        self::assertInstanceOf(Reservation::class, $stored);
        self::assertSame('Anna', $stored->getBooker()?->getFirstname());
        self::assertSame(['Lea'], array_map(static fn (Customer $guest): ?string => $guest->getFirstname(), $stored->getCustomers()->toArray()));
        self::assertSame(GuestCheckInStatus::APPLIED, $this->checkIn($reservation)->getStatus());
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

    private function submit(Reservation $reservation, ?string $message = null): void
    {
        $checkIn = new GuestCheckIn($reservation, 'Sel'.bin2hex(random_bytes(9)).'x');
        $checkIn->recordSubmission([
            'v' => 1,
            'arrivalTime' => '18:00',
            'message' => $message,
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

    private function createStoredGuest(string $firstname, string $lastname, ?string $birthday = null, ?string $email = null): Customer
    {
        $guest = new Customer();
        $guest->setSalutation('');
        $guest->setFirstname($firstname);
        $guest->setLastname($lastname);
        if (null !== $birthday) {
            $guest->setBirthday(new \DateTime($birthday));
        }
        if (null !== $email) {
            $address = (new CustomerAddresses())->setType('CUSTOMER_ADDRESS_TYPE_PRIVATE')->setEmail($email);
            $this->em()->persist($address);
            $guest->addCustomerAddress($address);
        }
        $this->em()->persist($guest);
        $this->em()->flush();
        $this->customerIds[] = (int) $guest->getId();

        return $guest;
    }
}
