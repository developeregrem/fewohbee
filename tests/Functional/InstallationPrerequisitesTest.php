<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Entity\User;
use App\Repository\PriceRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InstallationPrerequisitesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        // Keep the same connection so the empty-installation scenarios can be rolled back.
        $this->client->disableReboot();
        $this->client->catchExceptions(false);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();
        $this->connection->beginTransaction();

        $admin = $this->em->getRepository(User::class)->findOneBy(['username' => 'test-admin']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'main');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testReservationCannotBeStartedWithoutAnOrigin(): void
    {
        $this->removeOrigins();

        $this->client->request('GET', '/reservation/select/appartment', ['createNewReservation' => 'true']);

        $this->assertMissingOriginNotice();
    }

    public function testGuestSelectionShowsAHintWhenNoOriginsExist(): void
    {
        $customerId = $this->prepareReservationSelection();
        $this->removeOrigins();

        $this->client->request('POST', '/reservation/reservation/new/preview', ['customerid' => $customerId]);

        $this->assertMissingOriginNotice();
    }

    #[DataProvider('invalidOrigins')]
    public function testSavingRejectsAMissingOrUnknownOrigin(?string $originId): void
    {
        $crawler = $this->client->request('GET', '/settings/reservationorigin/new');
        $values = ['_csrf_token' => $crawler->filter('input[name="_csrf_token"]')->attr('value')];
        if (null !== $originId) {
            $values['reservation-origin'] = $originId;
        }
        $before = $this->em->getRepository(Reservation::class)->count([]);

        $this->client->request('POST', '/reservation/reservation/create', $values);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', $this->trans('reservation.flash.origin.required'));
        self::assertSame($before, $this->em->getRepository(Reservation::class)->count([]));
    }

    /** @return iterable<string, array{string|null}> */
    public static function invalidOrigins(): iterable
    {
        yield 'omitted' => [null];
        yield 'empty' => [''];
        yield 'invalid' => ['not-an-id'];
        yield 'zero' => ['0'];
        yield 'unknown' => ['2147483647'];
    }

    public function testReservationCanBePreviewedAndSavedWithAnOrigin(): void
    {
        $customerId = $this->prepareReservationSelection();
        $crawler = $this->client->request('POST', '/reservation/reservation/new/preview', ['customerid' => $customerId]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-action="click->reservations#createReservationsAction"]');
        $originId = $crawler->filter('#reservation-origin option')->first()->attr('value');
        $before = $this->em->getRepository(Reservation::class)->count([]);

        $this->client->request('POST', '/reservation/reservation/create', [
            '_csrf_token' => $crawler->filter('input[name="_csrf_token"]')->attr('value'),
            'reservation-origin' => $originId,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame($before + 1, $this->em->getRepository(Reservation::class)->count([]));
        $reservation = $this->em->getRepository(Reservation::class)->findOneBy([], ['id' => 'DESC']);
        self::assertSame((int) $originId, $reservation?->getReservationOrigin()?->getId());
    }

    #[DataProvider('priceLookups')]
    public function testPricesForALegacyReservationWithoutAnOriginAreEmpty(string $method): void
    {
        $room = $this->em->getRepository(Appartment::class)->findOneBy([]);
        self::assertInstanceOf(Appartment::class, $room);
        $reservation = new Reservation();
        $reservation->setAppartment($room);
        $reservation->setPersons(1);
        $reservation->setStartDate(new \DateTime('2030-01-01'));
        $reservation->setEndDate(new \DateTime('2030-01-03'));
        $repository = self::getContainer()->get(PriceRepository::class);
        self::assertGreaterThan(0, $repository->count([]));

        $prices = 'findApartmentPrices' === $method
            ? $repository->findApartmentPrices($reservation, 2)
            : $repository->$method($reservation);

        self::assertSame([], $prices);
    }

    /** @return iterable<string, array{string}> */
    public static function priceLookups(): iterable
    {
        foreach (['findApartmentPrices', 'findMiscPrices', 'findBookableOnlineExtras', 'findMandatoryOnlineExtras'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('roomOperations')]
    public function testRoomCannotBeSavedWithoutACategory(bool $editing): void
    {
        $room = $this->em->getRepository(Appartment::class)->findOneBy([]);
        self::assertInstanceOf(Appartment::class, $room);
        $roomId = $room->getId();
        $categoryId = $room->getRoomCategory()?->getId();
        $path = $editing ? sprintf('/settings/apartments/%d/edit', $roomId) : '/settings/apartments/new';
        $crawler = $this->client->request('GET', $path);
        $values = $this->roomFormValues($crawler);
        $values['apartment']['roomCategory'] = '';
        $before = $this->em->getRepository(Appartment::class)->count([]);

        $this->client->request('POST', $path, $values);

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('form[name="apartment"]', $this->trans('appartment.category.required', 'validators'));
        $this->em->clear();
        self::assertSame($before, $this->em->getRepository(Appartment::class)->count([]));
        self::assertSame($categoryId, $this->em->find(Appartment::class, $roomId)?->getRoomCategory()?->getId());
    }

    /** @return iterable<string, array{bool}> */
    public static function roomOperations(): iterable
    {
        yield 'create' => [false];
        yield 'edit' => [true];
    }

    public function testNoCategoriesShowsAHintAndPreventsRoomCreation(): void
    {
        $this->connection->executeStatement('UPDATE appartments SET room_category_id = NULL');
        $this->connection->executeStatement('DELETE FROM room_category');
        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(RoomCategory::class)->count([]));
        $crawler = $this->client->request('GET', '/settings/apartments/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', $this->trans('appartment.category.missing'));
        self::assertSelectorExists('a[href="/settings/category/"]');
        $values = $this->roomFormValues($crawler);
        $before = $this->em->getRepository(Appartment::class)->count([]);

        $this->client->request('POST', '/settings/apartments/new', $values);

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('form[name="apartment"]', $this->trans('appartment.category.required', 'validators'));
        self::assertSame($before, $this->em->getRepository(Appartment::class)->count([]));
    }

    public function testRoomCanBeCreatedWithACategory(): void
    {
        $crawler = $this->client->request('GET', '/settings/apartments/new');
        $values = $this->roomFormValues($crawler);
        $category = $this->em->getRepository(RoomCategory::class)->findOneBy([]);
        self::assertInstanceOf(RoomCategory::class, $category);
        $values['apartment']['roomCategory'] = (string) $category->getId();

        $this->client->request('POST', '/settings/apartments/new', $values);

        self::assertResponseStatusCodeSame(204);
        $this->em->clear();
        $room = $this->em->getRepository(Appartment::class)->findOneBy(['number' => 'REQ-1']);
        self::assertInstanceOf(Appartment::class, $room);
        self::assertSame($category->getId(), $room->getRoomCategory()?->getId());
    }

    private function assertMissingOriginNotice(): void
    {
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', $this->trans('reservation.origin.missing'));
        self::assertSelectorExists('a[href="/settings/reservationorigin/"]');
        self::assertSelectorNotExists('[data-action="click->reservations#createReservationsAction"]');
    }

    private function removeOrigins(): void
    {
        $this->connection->executeStatement('UPDATE reservations SET reservation_origin_id = NULL');
        $this->connection->executeStatement('DELETE FROM calendar_sync_import');
        $this->connection->executeStatement('DELETE FROM reservation_origins');
        $this->em->clear();
    }

    private function prepareReservationSelection(): int
    {
        $room = $this->em->getRepository(Appartment::class)->findOneBy(['active' => true]);
        $status = $this->em->getRepository(ReservationStatus::class)->findOneBy(['code' => null]);
        $customer = $this->em->getRepository(Customer::class)->findOneBy([]);
        self::assertInstanceOf(Appartment::class, $room);
        self::assertInstanceOf(ReservationStatus::class, $status);
        self::assertInstanceOf(Customer::class, $customer);

        $this->client->request('POST', '/reservation/appartments/selectable/add/to/reservation', [
            'createNewReservation' => 'true',
            'status' => (string) $status->getId(),
            'apartments' => [[
                'id' => (string) $room->getId(),
                'from' => '2030-01-01',
                'end' => '2030-01-03',
            ]],
        ]);
        self::assertResponseIsSuccessful();

        return (int) $customer->getId();
    }

    /** @return array<string, mixed> */
    private function roomFormValues(Crawler $crawler): array
    {
        $values = $crawler->filter('form[name="apartment"]')->form()->getPhpValues();
        $subsidiary = $this->em->getRepository(Subsidiary::class)->findOneBy([]);
        self::assertInstanceOf(Subsidiary::class, $subsidiary);
        $values['apartment']['number'] = 'REQ-1';
        $values['apartment']['description'] = 'Category requirement';
        $values['apartment']['bedsMax'] = '1';
        $values['apartment']['object'] = (string) $subsidiary->getId();

        return $values;
    }

    private function trans(string $key, string $domain = 'messages'): string
    {
        return self::getContainer()->get(TranslatorInterface::class)->trans($key, [], $domain);
    }
}
