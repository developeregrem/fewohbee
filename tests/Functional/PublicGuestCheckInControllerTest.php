<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AppSettings;
use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Enum\GuestCheckInFieldMode;
use App\Entity\Enum\GuestCheckInStatus;
use App\Entity\GuestCheckIn;
use App\Entity\GuestCheckInConfig;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Service\GuestCheckIn\GuestCheckInLinkService;
use App\Service\GuestCheckIn\GuestCheckInRateLimiter;
use App\Service\GuestCheckIn\GuestCheckInTokenSigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Uid\Uuid;

/**
 * The public check-in page: nothing without a valid link, nothing but the house name before the
 * booking-details check, and guest input never reaches the guest records directly.
 */
final class PublicGuestCheckInControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private ?int $reservationId = null;
    private ?int $bookerId = null;
    /** @var list<int> further customers created by a test */
    private array $extraCustomerIds = [];
    private string $start;
    private string $end;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->start = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $this->end = (new \DateTimeImmutable('+12 days'))->format('Y-m-d');

        $config = $this->em()->getRepository(GuestCheckInConfig::class)->findOneBy([]) ?? throw new \RuntimeException('Config row missing.');
        // Independent of what other tests left behind: every group shown, ID and contact optional.
        $config->setEnabled(true)
            ->setAddressMode(GuestCheckInFieldMode::REQUIRED)
            ->setBirthdayMode(GuestCheckInFieldMode::REQUIRED)
            ->setNationalityMode(GuestCheckInFieldMode::REQUIRED)
            ->setIdDocumentMode(GuestCheckInFieldMode::OPTIONAL)
            ->setContactMode(GuestCheckInFieldMode::OPTIONAL)
            ->setCompanionsMode(GuestCheckInFieldMode::OPTIONAL);
        $this->em()->getRepository(AppSettings::class)->findOneBy([])?->setPublicBaseUrl('https://fewohbee.example.com');
        $this->em()->flush();
    }

    protected function tearDown(): void
    {
        $em = $this->em();
        $connection = $em->getConnection();
        if (null !== $this->reservationId) {
            $connection->executeStatement('DELETE FROM guest_check_in WHERE reservation_id = ?', [$this->reservationId]);
            $connection->executeStatement('DELETE FROM reservations_has_customers WHERE reservation_id = ?', [$this->reservationId]);
            $connection->executeStatement('DELETE FROM reservations WHERE id = ?', [$this->reservationId]);
        }
        foreach (array_filter([$this->bookerId, ...$this->extraCustomerIds]) as $customerId) {
            $addressIds = $connection->fetchFirstColumn('SELECT customer_addresses_id FROM customer_has_address WHERE customer_id = ?', [$customerId]);
            $connection->executeStatement('DELETE FROM customer_has_address WHERE customer_id = ?', [$customerId]);
            foreach ($addressIds as $addressId) {
                $connection->executeStatement('DELETE FROM customer_addresses WHERE id = ?', [$addressId]);
            }
            $connection->executeStatement('DELETE FROM customers WHERE id = ?', [$customerId]);
        }
        $connection->executeStatement('UPDATE guest_check_in_config SET enabled = 0');
        $connection->executeStatement('UPDATE app_settings SET public_base_url = NULL');
        parent::tearDown();
    }

    public function testGateShowsOnlyTheHouseAndSetsNoCookie(): void
    {
        $path = $this->linkPath($this->createReservation());

        $crawler = $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="guest_check_in_verification"] input[name="guest_check_in_verification[lastname]"]');
        self::assertSelectorNotExists('form[name="guest_check_in"]');
        self::assertStringNotContainsString('Müller', $crawler->html());
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        $this->assertPrivacyHeaders();
    }

    public function testForgedAndRevokedLinksGetTheSameNeutralPage(): void
    {
        $reservation = $this->createReservation();
        $path = $this->linkPath($reservation);
        self::getContainer()->get(GuestCheckInLinkService::class)->regenerate($reservation);

        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);
        $revoked = (string) $this->client->getResponse()->getContent();
        $this->assertPrivacyHeaders();

        $this->client->request('GET', '/checkin/'.str_repeat('A', GuestCheckInTokenSigner::TOKEN_LENGTH));
        self::assertResponseStatusCodeSame(404);
        self::assertSame($revoked, (string) $this->client->getResponse()->getContent());
    }

    public function testWrongBookingDetailsSetNoCookie(): void
    {
        $path = $this->linkPath($this->createReservation());

        $this->verify($path, 'Schmidt');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.alert-warning');
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    public function testCorrectDetailsOpenTheFormWithAStrictPathCookie(): void
    {
        $path = $this->linkPath($this->createReservation());

        // Spelling variants of the stored "Müller" are accepted.
        $this->verify($path, 'mueller');

        self::assertResponseStatusCodeSame(303);
        $cookies = $this->client->getResponse()->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame($path, $cookies[0]->getPath());
        self::assertTrue($cookies[0]->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_STRICT, $cookies[0]->getSameSite());

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="guest_check_in"]');
        // Main guest plus one fellow traveller, each in a block of their own.
        self::assertSelectorCount(2, 'details.fhb-gci-person');
        $number = (string) $this->em()->find(Reservation::class, $this->reservationId)?->getAppartment()?->getNumber();
        $facts = $crawler->filter('.fhb-gci-facts')->text();
        self::assertStringContainsString($number.' · ', $facts);
        self::assertMatchesRegularExpression('/2 (Nächte|nights)/', $facts);
    }

    public function testSubmissionIsStoredWithoutTouchingGuestRecords(): void
    {
        $reservation = $this->createReservation();
        $path = $this->linkPath($reservation);
        $this->verify($path, 'Müller');
        $crawler = $this->client->followRedirect();

        $form = $crawler->filter('form[name="guest_check_in"]')->form();
        $form['guest_check_in[arrivalTime]'] = '18:00';
        $form['guest_check_in[mainGuest][salutation]'] = 'Ms';
        $form['guest_check_in[mainGuest][firstname]'] = 'Anna';
        $form['guest_check_in[mainGuest][lastname]'] = 'Müller';
        $form['guest_check_in[mainGuest][birthday]'] = '1980-05-01';
        $form['guest_check_in[mainGuest][nationality]'] = 'AT';
        $form['guest_check_in[mainGuest][street]'] = 'Hauptstraße 1';
        $form['guest_check_in[mainGuest][zip]'] = '1010';
        $form['guest_check_in[mainGuest][city]'] = 'Wien';
        $form['guest_check_in[mainGuest][country]'] = 'AT';
        $form['guest_check_in[mainGuest][idType]'] = 'customer.id.type.passport';
        $form['guest_check_in[mainGuest][idNumber]'] = 'P1234567';
        $form['guest_check_in[companions][0][firstname]'] = 'Max';
        $form['guest_check_in[companions][0][lastname]'] = 'Müller';
        $form['guest_check_in[companions][0][birthday]'] = '1982-02-02';
        $form['guest_check_in[companions][0][nationality]'] = 'AT';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(303);
        $this->em()->clear();
        $checkIn = $this->checkIn();
        self::assertSame(GuestCheckInStatus::SUBMITTED, $checkIn->getStatus());
        self::assertSame('P1234567', $checkIn->getPayload()['mainGuest']['idNumber'] ?? null);
        self::assertSame('Max', $checkIn->getPayload()['companions'][0]['firstname'] ?? null);

        $stored = $this->em()->find(Reservation::class, $reservation->getId());
        self::assertSame('18:00', $stored?->getArrivalTime()?->format('H:i'));
        $booker = $this->em()->find(Customer::class, $this->bookerId);
        self::assertNull($booker?->getBirthday(), 'The public form never writes guest records.');
        self::assertNull($booker->getNationality());
        self::assertCount(1, $stored->getCustomers());

        // The confirmation shows names and time, never the ID number.
        $crawler = $this->client->followRedirect();
        self::assertSelectorExists('.alert-success');
        self::assertStringContainsString('Max Müller', $crawler->html());
        self::assertStringNotContainsString('P1234567', $crawler->html());
    }

    public function testReturningGuestFindsTheFormPrefilledButNoIdNumber(): void
    {
        $reservation = $this->createReservation();
        $booker = $reservation->getBooker();
        self::assertInstanceOf(Customer::class, $booker);
        $booker->setBirthday(new \DateTime('1980-05-01'));
        $booker->setIDNumber('P99887766');
        $address = (new CustomerAddresses())->setType('CUSTOMER_ADDRESS_TYPE_PRIVATE')->setAddress('Herrengasse 3')->setZip('8010')->setCity('Graz')->setCountry('AT');
        $this->em()->persist($address);
        $booker->addCustomerAddress($address);
        $companion = new Customer();
        $companion->setSalutation('');
        $companion->setFirstname('Lena');
        $companion->setLastname('Müller');
        $this->em()->persist($companion);
        $reservation->addCustomer($companion);
        $this->em()->flush();
        $this->extraCustomerIds[] = (int) $companion->getId();

        $path = $this->linkPath($reservation);
        $this->verify($path, 'Müller');
        $crawler = $this->client->followRedirect();

        $value = static fn (string $name): ?string => $crawler->filter(sprintf('[name="%s"]', $name))->attr('value');
        self::assertSame('Anna', $value('guest_check_in[mainGuest][firstname]'));
        self::assertSame('1980-05-01', $value('guest_check_in[mainGuest][birthday]'));
        self::assertSame('Graz', $value('guest_check_in[mainGuest][city]'));
        self::assertSame('Lena', $value('guest_check_in[companions][0][firstname]'));
        self::assertSame('', (string) $value('guest_check_in[mainGuest][idNumber]'));
        self::assertStringNotContainsString('P99887766', $crawler->html());
        self::assertStringContainsString('•••766', $crawler->html());
    }

    public function testFormPostWithoutCheckStoresNothing(): void
    {
        $path = $this->linkPath($this->createReservation());

        $this->client->request('POST', $path, ['guest_check_in' => ['arrivalTime' => '18:00']]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="guest_check_in_verification"]');
        self::assertFalse($this->checkIn()->hasPayload());
    }

    public function testTamperedCookieLeadsBackToTheCheck(): void
    {
        $path = $this->linkPath($this->createReservation());
        $this->client->getCookieJar()->set(new BrowserKitCookie('fhb_gci', 'v1.'.(time() + 3600).'.'.str_repeat('0', 64), null, $path));

        $this->client->request('GET', $path);

        self::assertSelectorExists('form[name="guest_check_in_verification"]');
    }

    public function testBookingWithoutNameIsCheckedByDatesOnly(): void
    {
        $path = $this->linkPath($this->createReservation(withBooker: false));

        $crawler = $this->client->request('GET', $path);
        self::assertSelectorNotExists('input[name="guest_check_in_verification[lastname]"]');

        $form = $crawler->filter('form[name="guest_check_in_verification"]')->form();
        $form['guest_check_in_verification[arrival]'] = $this->start;
        $form['guest_check_in_verification[departure]'] = $this->end;
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(303);
    }

    public function testLanguageFollowsTheBrowserAndTheSwitcher(): void
    {
        $path = $this->linkPath($this->createReservation());

        $this->client->request('GET', $path, server: ['HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.9']);
        self::assertSelectorTextContains('html', 'Please confirm your booking first');

        $this->client->request('GET', $path.'?lang=de', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-GB']);
        self::assertSelectorTextContains('html', 'Bitte bestätigen Sie zuerst Ihre Buchung');
    }

    public function testCancelledReservationHasNoCheckIn(): void
    {
        $reservation = $this->createReservation();
        $path = $this->linkPath($reservation);
        $cancelled = $this->em()->getRepository(ReservationStatus::class)->findOneBy(['isBlocking' => false]);
        if (!$cancelled instanceof ReservationStatus) {
            self::markTestSkipped('The sample data has no non-blocking status.');
        }
        $reservation->setReservationStatus($cancelled);
        $this->em()->flush();

        $this->client->request('GET', $path);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTooManyChecksAreRefused(): void
    {
        $path = $this->linkPath($this->createReservation());
        $limiter = $this->createStub(GuestCheckInRateLimiter::class);
        $limiter->method('isLinkGuessingBlocked')->willReturn(false);
        $limiter->method('isVerificationBlocked')->willReturn(true);
        $this->client->disableReboot();
        self::getContainer()->set(GuestCheckInRateLimiter::class, $limiter);

        $this->verify($path, 'Müller');

        self::assertResponseStatusCodeSame(429);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    private function verify(string $path, string $lastname): void
    {
        $crawler = $this->client->request('GET', $path);
        $form = $crawler->filter('form[name="guest_check_in_verification"]')->form();
        $form['guest_check_in_verification[arrival]'] = $this->start;
        $form['guest_check_in_verification[departure]'] = $this->end;
        $form['guest_check_in_verification[lastname]'] = $lastname;
        $this->client->submit($form);
    }

    private function assertPrivacyHeaders(): void
    {
        $headers = $this->client->getResponse()->headers;
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        self::assertSame('noindex, nofollow', $headers->get('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertStringContainsString("script-src 'none'", (string) $headers->get('Content-Security-Policy'));
    }

    private function linkPath(Reservation $reservation): string
    {
        $url = self::getContainer()->get(GuestCheckInLinkService::class)->url($reservation);
        self::assertIsString($url);

        return (string) parse_url($url, \PHP_URL_PATH);
    }

    private function checkIn(): GuestCheckIn
    {
        return $this->em()->getRepository(GuestCheckIn::class)->findOneBy(['reservation' => $this->reservationId])
            ?? throw new \RuntimeException('Check-in row missing.');
    }

    private function createReservation(bool $withBooker = true): Reservation
    {
        $em = $this->em();
        $apartment = $em->getRepository(Appartment::class)->findOneBy([]) ?? throw new \RuntimeException('No apartment in the test data.');
        $status = $em->getRepository(ReservationStatus::class)->findOneBy(['isBlocking' => true]) ?? throw new \RuntimeException('No blocking status.');

        $reservation = new Reservation();
        $reservation->setReservationOrigin($em->getRepository(ReservationOrigin::class)->findOneBy([]));
        $reservation->setReservationStatus($status);
        $reservation->setPersons(2);
        $reservation->setStartDate(new \DateTime($this->start));
        $reservation->setEndDate(new \DateTime($this->end));
        $reservation->setAppartment($apartment);
        $reservation->setReservationDate(new \DateTime());
        $reservation->setIsConflict(false);
        $reservation->setIsConflictIgnored(false);
        $reservation->setUuid(Uuid::v4());

        if ($withBooker) {
            $booker = new Customer();
            $booker->setSalutation('Ms');
            $booker->setFirstname('Anna');
            $booker->setLastname('Müller');
            $em->persist($booker);
            $reservation->setBooker($booker);
            $reservation->addCustomer($booker);
        }

        $em->persist($reservation);
        $em->flush();
        $this->reservationId = $reservation->getId();
        $this->bookerId = $withBooker ? $reservation->getBooker()?->getId() : null;

        return $reservation;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
