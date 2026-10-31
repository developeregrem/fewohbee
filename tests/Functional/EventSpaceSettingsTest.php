<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\Enum\RoomCategoryKind;
use App\Entity\Price;
use App\Entity\ReservationOrigin;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Entity\User;
use App\Repository\AppartmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The kind of room at the room category: saving it, keeping prices unmixed, labels and online booking. */
final class EventSpaceSettingsTest extends WebTestCase
{
    private const PREFIX = 'event-space-test ';
    private const ROOM_NUMBER = 'EVT-1';

    public function testCategoryIsSavedAsEventSpace(): void
    {
        $client = $this->authenticatedClient();

        $crawler = $client->request('GET', '/settings/category/new');
        self::assertSelectorExists('input[name="room_category[kind]"][value="event_space"]');
        $values = $crawler->filter('form[name="room_category"]')->form()->getPhpValues();
        $values['room_category']['name'] = self::PREFIX.'hall';
        $values['room_category']['acronym'] = 'H';
        $values['room_category']['kind'] = 'event_space';
        $client->request('POST', '/settings/category/new', $values);

        self::assertResponseStatusCodeSame(204);
        $this->em()->clear();
        $category = $this->em()->getRepository(RoomCategory::class)->findOneBy(['name' => self::PREFIX.'hall']);
        self::assertSame(RoomCategoryKind::EVENT_SPACE, $category?->getKind());
    }

    public function testKindCannotChangeWhileThePriceIsSharedWithAccommodation(): void
    {
        $client = $this->authenticatedClient();
        $category = $this->category('seminar', RoomCategoryKind::ACCOMMODATION);
        $this->postPrice($client, 'shared', [(int) $category->getId(), $this->sampleCategoryId()]);
        self::assertNotNull($this->findPrice('shared'), 'The shared price must have been saved.');

        $crawler = $client->request('GET', sprintf('/settings/category/%d/edit', $category->getId()));
        $values = $crawler->filter('form[name="room_category"]')->form()->getPhpValues();
        $values['room_category']['kind'] = 'event_space';
        $client->request('POST', sprintf('/settings/category/%d/edit', $category->getId()), $values);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('form[name="room_category"]', self::PREFIX.'shared');
        $this->em()->clear();
        self::assertSame(RoomCategoryKind::ACCOMMODATION, $this->em()->find(RoomCategory::class, $category->getId())?->getKind());
    }

    public function testPriceMixingAccommodationAndEventSpacesIsRejected(): void
    {
        $client = $this->authenticatedClient();
        $hall = $this->category('hall', RoomCategoryKind::EVENT_SPACE);

        $crawler = $this->postPrice($client, 'mixed', [(int) $hall->getId(), $this->sampleCategoryId()]);

        self::assertStringContainsString($this->trans('price.flash.mixed_room_kinds'), $crawler->filter('.alert')->text());
        self::assertNull($this->findPrice('mixed'));
    }

    public function testPriceFormNamesDaysForAnEventSpace(): void
    {
        $client = $this->authenticatedClient();
        $hall = $this->category('hall', RoomCategoryKind::EVENT_SPACE);
        $this->postPrice($client, 'hall per day', [(int) $hall->getId()]);
        $price = $this->findPrice('hall per day');
        self::assertNotNull($price);

        $client->request('GET', sprintf('/settings/prices/%d/get', $price->getId()));
        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('label[for="calc-per-room-'.$price->getId().'"]', $this->trans('price.calculation_type.per_room.day'));
        self::assertSelectorExists('option[value="'.$hall->getId().'"][data-event-space="1"]');
    }

    public function testEventSpaceIsNotOfferedForOnlineBooking(): void
    {
        $client = $this->authenticatedClient();
        $hall = $this->category('hall', RoomCategoryKind::EVENT_SPACE);
        $subsidiary = $this->em()->getRepository(Subsidiary::class)->findOneBy([]) ?? throw new \RuntimeException('Sample subsidiary missing.');
        $room = new Appartment();
        $room->setNumber(self::ROOM_NUMBER);
        $room->setDescription('Festsaal');
        $room->setBedsMax(60);
        $room->setObject($subsidiary);
        $room->setRoomCategory($hall);
        $this->em()->persist($room);
        $this->em()->flush();

        self::assertSame([], self::getContainer()->get(AppartmentRepository::class)->findForPublicBooking([(int) $room->getId()], [(int) $subsidiary->getId()]));

        $client->request('GET', '/settings/online-booking');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="online_booking_config[selectedRoomIds][]"][value="'.$room->getId().'"]');
        self::assertSelectorTextContains('body', $this->trans('online_booking.settings.rooms_mode_event_spaces'));
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $em = $this->em();
            if ($em->isOpen()) {
                $em->clear();
                foreach ($em->getRepository(Price::class)->findAll() as $price) {
                    if (str_starts_with((string) $price->getDescription(), self::PREFIX)) {
                        $em->remove($price);
                    }
                }
                foreach ($em->getRepository(Appartment::class)->findAll() as $room) {
                    if (self::ROOM_NUMBER === $room->getNumber()) {
                        $em->remove($room);
                    }
                }
                $em->flush();
                foreach ($em->getRepository(RoomCategory::class)->findAll() as $category) {
                    if (str_starts_with((string) $category->getName(), self::PREFIX)) {
                        $em->remove($category);
                    }
                }
                $em->flush();
            }
        }
        parent::tearDown();
    }

    private function category(string $name, RoomCategoryKind $kind): RoomCategory
    {
        $category = (new RoomCategory())->setName(self::PREFIX.$name)->setKind($kind);
        $this->em()->persist($category);
        $this->em()->flush();

        return $category;
    }

    /**
     * Submits the price form the way the settings modal does: a room price per room.
     *
     * @param list<int> $categoryIds
     */
    private function postPrice(KernelBrowser $client, string $name, array $categoryIds): Crawler
    {
        // The legacy CSRF token lives in the session and is created by rendering the form.
        $form = $client->request('GET', '/settings/prices/new');
        $token = $form->filter('input[name="_csrf_token"]')->attr('value');
        $origin = $this->em()->getRepository(ReservationOrigin::class)->findOneBy([]);

        return $client->request('POST', '/settings/prices/create', [
            '_csrf_token' => $token,
            'description-new' => self::PREFIX.$name,
            'price-new' => '300,00',
            'vat-new' => '19',
            'type-new' => '2',
            'origin-new' => [(string) $origin?->getId()],
            'category-new' => array_map(strval(...), $categoryIds),
            'calculation-type-new' => 'per_room',
            'active-new' => '1',
            'alldays-new' => '1',
            'allperiods-new' => '1',
            // An occupancy and minimum stay no sample price uses, so nothing conflicts.
            'number-of-persons-new' => '47',
            'min-stay-new' => '17',
        ]);
    }

    private function findPrice(string $name): ?Price
    {
        $this->em()->clear();

        return $this->em()->getRepository(Price::class)->findOneBy(['description' => self::PREFIX.$name]);
    }

    private function sampleCategoryId(): int
    {
        $category = $this->em()->getRepository(RoomCategory::class)->findOneBy([], ['id' => 'ASC']) ?? throw new \RuntimeException('Sample room category missing.');

        return (int) $category->getId();
    }

    private function trans(string $key): string
    {
        return self::getContainer()->get(TranslatorInterface::class)->trans($key);
    }

    private function authenticatedClient(): KernelBrowser
    {
        $client = self::createClient();
        $admin = $this->em()->getRepository(User::class)->findOneBy(['username' => 'test-admin']) ?? throw new \RuntimeException('Prepared test admin missing.');
        $client->loginUser($admin, 'main');

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
