<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\Price;
use App\Entity\PricePeriod;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\RoomCategory;
use App\Entity\User;
use App\Repository\PriceRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/** HTTP and persistence coverage for prices bound to several room categories, including the conflict check. */
final class PriceSettingsControllerTest extends WebTestCase
{
    private const PREFIX = 'multi-category-test ';

    // Occupancy and minimum stay no sample price uses, so only prices created here can conflict.
    private const PERSONS = 9;
    private const MIN_STAY = 13;

    public function testRoomPriceIsSavedWithSeveralCategories(): void
    {
        $client = $this->authenticatedClient();
        [$single, $double] = $this->categoryIds();

        $this->postPrice($client, 'both', 2, [$single, $double]);

        self::assertResponseIsSuccessful();
        $price = $this->findPrice('both');
        self::assertNotNull($price);
        self::assertEqualsCanonicalizing([$single, $double], $this->idsOf($price));
    }

    public function testZeroVatPriceCanBeCreatedAndEditedToAZeroAmount(): void
    {
        $client = $this->authenticatedClient();
        $crawler = $this->postPrice($client, 'zero-vat', 1, [], overrides: [
            'price-new' => '12,34',
            'vat-new' => '0',
        ]);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.alert-warning'));
        $price = $this->findPrice('zero-vat');
        self::assertNotNull($price);
        self::assertSame('12.34', $price->getPrice());
        self::assertSame(0.0, $price->getVat());
        $id = (string) $price->getId();

        $crawler = $this->postPrice($client, 'zero-vat', 1, [], $id, [
            'price-'.$id => '0,00',
            'vat-'.$id => '0',
        ]);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.alert-warning'));
        $edited = $this->findPrice('zero-vat');
        self::assertNotNull($edited);
        self::assertSame('0.00', $edited->getPrice());
        self::assertSame(0.0, $edited->getVat());
    }

    public function testRoomPriceSharingACategoryIsReportedAsConflict(): void
    {
        $client = $this->authenticatedClient();
        [$single, $double] = $this->categoryIds();

        $this->postPrice($client, 'first', 2, [$single, $double]);
        self::assertNotNull($this->findPrice('first'));

        // Shares the double room category with the first price.
        $crawler = $this->postPrice($client, 'overlapping', 2, [$double]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::PREFIX.'first', $crawler->filter('.alert ul')->text());
        self::assertNull($this->findPrice('overlapping'));
    }

    public function testRoomPricesWithDisjointCategoriesDoNotConflict(): void
    {
        $client = $this->authenticatedClient();
        [$single, $double] = $this->categoryIds();

        $this->postPrice($client, 'single', 2, [$single]);
        $crawler = $this->postPrice($client, 'double', 2, [$double]);

        self::assertCount(0, $crawler->filter('.alert'));
        self::assertNotNull($this->findPrice('single'));
        self::assertNotNull($this->findPrice('double'));
    }

    public function testEditingARoomPriceDoesNotConflictWithItself(): void
    {
        $client = $this->authenticatedClient();
        [$single, $double] = $this->categoryIds();

        $this->postPrice($client, 'editable', 2, [$single, $double]);
        $id = (int) $this->findPrice('editable')?->getId();

        $crawler = $this->postPrice($client, 'editable', 2, [$double], (string) $id);

        self::assertCount(0, $crawler->filter('.alert'));
        self::assertSame([$double], $this->idsOf($this->findPrice('editable')));
    }

    public function testSpecialPeriodKeepsItsDescription(): void
    {
        $client = $this->authenticatedClient();
        [$single] = $this->categoryIds();

        $this->postPrice($client, 'event', 2, [$single], 'new', [
            'allperiods-new' => '',
            'period-new' => ['new'],
            'periodstart-new' => ['2031-05-01'],
            'periodend-new' => ['2031-05-03'],
            'perioddescription-new' => ['  Stadtfest  '],
        ]);

        self::assertResponseIsSuccessful();
        $period = $this->findPrice('event')?->getPricePeriods()->first();
        self::assertInstanceOf(PricePeriod::class, $period);
        self::assertSame('Stadtfest', $period->getDescription());
        self::assertSame('2031-05-03', $period->getEnd()?->format('Y-m-d'));

        $client->request('GET', '/settings/prices/');
        self::assertStringContainsString('01.05.2031 - 03.05.2031 <span class="text-body-secondary small">Stadtfest</span>', (string) $client->getResponse()->getContent());
    }

    #[DataProvider('rejectedEdits')]
    public function testRejectedEditsLeaveThePriceAndItsChildrenUnchanged(string $reason): void
    {
        $client = $this->authenticatedClient();
        [$single, $double] = $this->categoryIds();
        $type = 'components' === $reason ? 1 : 2;
        $overrides = [
            'allperiods-new' => '',
            'period-new' => ['new'],
            'periodstart-new' => ['2031-05-01'],
            'periodend-new' => ['2031-05-03'],
            'perioddescription-new' => ['Original period'],
        ];
        if ('components' === $reason) {
            $overrides += [
                'is-package-new' => '1',
                'component-desc-new' => ['Original component'],
                'component-vat-new' => ['7'],
                'component-type-new' => ['percent'],
                'component-value-new' => ['100'],
            ];
        }
        if ('conflict' === $reason) {
            $this->postPrice($client, 'conflicting', 2, [$single], overrides: [
                'allperiods-new' => '',
                'period-new' => ['new'],
                'periodstart-new' => ['2031-06-01'],
                'periodend-new' => ['2031-06-03'],
            ]);
        }
        $this->postPrice($client, 'protected', $type, [$double], overrides: $overrides);
        $price = $this->findPrice('protected');
        self::assertNotNull($price);
        $id = (string) $price->getId();
        $periodIds = $price->getPricePeriods()->map(static fn (PricePeriod $period): int => $period->getId())->getValues();

        $edited = [
            'price-'.$id => '99,00',
            'allperiods-'.$id => '',
            'period-'.$id => ['new'],
            'periodstart-new' => ['2031-06-01'],
            'periodend-new' => ['2031-06-03'],
            'perioddescription-new' => ['Rejected period'],
        ];
        if ('components' === $reason) {
            $edited += [
                'is-package-'.$id => '1',
                'component-desc-'.$id => ['Rejected component'],
                'component-vat-'.$id => ['-1'],
                'component-type-'.$id => ['percent'],
                'component-value-'.$id => ['100'],
            ];
        }

        $crawler = $this->postPrice($client, 'rejected', $type, 'mandatory' === $reason ? [] : [$single], $id, $edited);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.alert-warning'));
        // A later flush must not save the rejected form's mutations or orphan removals.
        $this->em()->flush();
        $saved = $this->findPrice('protected');
        self::assertNotNull($saved);
        self::assertSame(80.0, (float) $saved->getPrice());
        self::assertSame([$double], $this->idsOf($saved));
        self::assertSame($periodIds, $saved->getPricePeriods()->map(static fn (PricePeriod $period): int => $period->getId())->getValues());
        $period = $saved->getPricePeriods()->first();
        self::assertInstanceOf(PricePeriod::class, $period);
        self::assertSame('Original period', $period->getDescription());
        self::assertSame('2031-05-01', $period->getStart()?->format('Y-m-d'));
        if ('components' === $reason) {
            self::assertCount(1, $saved->getComponents());
            self::assertSame('Original component', $saved->getComponents()->first()->getDescription());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedEdits(): iterable
    {
        yield 'missing room category' => ['mandatory'];
        yield 'invalid package component' => ['components'];
        yield 'overlapping room price' => ['conflict'];
    }

    public function testRoomPriceWithoutCategoryIsRejected(): void
    {
        $client = $this->authenticatedClient();

        $crawler = $this->postPrice($client, 'no-category', 2, []);

        self::assertCount(1, $crawler->filter('.alert'));
        self::assertNull($this->findPrice('no-category'));
    }

    public function testExtraNameIsSavedInFurtherGuestLanguagesOnly(): void
    {
        $client = $this->authenticatedClient();

        $this->postPrice($client, 'breakfast', 1, [], overrides: [
            'translations-new' => [
                'en' => ['description' => 'Breakfast', 'price' => '1,00'],
                'de' => ['description' => 'Frühstück überschrieben'],
                'xx' => ['description' => 'ignored'],
            ],
        ]);

        $price = $this->findPrice('breakfast');
        self::assertNotNull($price);
        // Only offered extra languages and translatable fields are taken over; German is the stored name.
        self::assertSame(['en' => ['description' => 'Breakfast']], $price->getContentTranslations());
    }

    public function testMiscPriceAppliesOnlyToItsCategories(): void
    {
        $client = $this->authenticatedClient();
        [$single, $double] = $this->categoryIds();

        $this->postPrice($client, 'single-extra', 1, [$single]);
        $this->postPrice($client, 'all-extra', 1, []);

        $singleExtra = $this->findPrice('single-extra');
        self::assertNotNull($singleExtra);
        self::assertSame([$single], $this->idsOf($singleExtra));

        $inSingle = $this->miscPriceDescriptions($single);
        $inDouble = $this->miscPriceDescriptions($double);
        self::assertContains(self::PREFIX.'single-extra', $inSingle);
        self::assertNotContains(self::PREFIX.'single-extra', $inDouble);
        // No selection keeps applying to every category.
        self::assertContains(self::PREFIX.'all-extra', $inSingle);
        self::assertContains(self::PREFIX.'all-extra', $inDouble);
    }

    public function testExtrasOfAStayAreListedByName(): void
    {
        $client = $this->authenticatedClient();
        [$single] = $this->categoryIds();
        // Created against the alphabet; the seasonal one used to come first.
        $this->postPrice($client, 'extra-c', 1, [$single]);
        $this->postPrice($client, 'extra-a', 1, [$single]);
        $this->postPrice($client, 'extra-b', 1, [$single], 'new', [
            'allperiods-new' => '',
            'period-new' => ['new'],
            'periodstart-new' => [(new \DateTime('+390 days'))->format('Y-m-d')],
            'periodend-new' => [(new \DateTime('+410 days'))->format('Y-m-d')],
        ]);

        $ours = array_values(array_filter(
            $this->miscPriceDescriptions($single),
            static fn (string $description): bool => str_starts_with($description, self::PREFIX),
        ));

        self::assertSame([self::PREFIX.'extra-a', self::PREFIX.'extra-b', self::PREFIX.'extra-c'], $ours);
    }

    public function testOverviewShowsTheCategoryCombinationOnceWithRoomPricesBeforeMiscPrices(): void
    {
        $client = $this->authenticatedClient();
        [$single, $double] = $this->categoryIds();
        $this->postPrice($client, 'overview-extra', 1, [$single, $double]);
        $this->postPrice($client, 'overview-room', 2, [$single, $double]);

        $crawler = $client->request('GET', '/settings/prices/');

        self::assertResponseIsSuccessful();
        $cards = $crawler->filter('.card')->reduce(
            static fn ($card): bool => str_contains($card->text(), self::PREFIX.'overview')
        );
        self::assertCount(1, $cards);
        // Categories are listed in creation order.
        $em = $this->em();
        $names = array_map(static fn (int $id): string => (string) $em->find(RoomCategory::class, $id)?->getName(), [$single, $double]);
        self::assertStringContainsString(implode(', ', $names), $cards->filter('.card-header')->text());

        $sections = $cards->filter('tbody');
        self::assertCount(2, $sections);
        self::assertStringContainsString(self::PREFIX.'overview-room', $sections->eq(0)->text());
        self::assertStringContainsString(self::PREFIX.'overview-extra', $sections->eq(1)->text());
    }

    public function testOverviewKeepsCategoriesInCreationOrder(): void
    {
        $client = $this->authenticatedClient();

        $crawler = $client->request('GET', '/settings/prices/');

        self::assertResponseIsSuccessful();
        $headers = $crawler->filter('.card-header')->each(static fn ($header): string => trim($header->filter('strong')->text()));
        $expected = array_map(
            static fn (RoomCategory $category): string => (string) $category->getName(),
            $this->em()->getRepository(RoomCategory::class)->findBy([], ['id' => 'ASC']),
        );
        // Cards of single categories appear in id order; categories without prices have no card.
        $present = array_values(array_intersect($headers, $expected));
        self::assertNotEmpty($present, 'Sample data must contain prices for a single room category.');
        self::assertSame(array_values(array_intersect($expected, $present)), $present);
    }

    public function testOverviewListsPricesByName(): void
    {
        $client = $this->authenticatedClient();
        [$single, $double] = $this->categoryIds();
        $this->postPrice($client, 'overview-b', 1, [$single, $double]);
        $this->postPrice($client, 'overview-a', 1, [$single, $double]);

        $crawler = $client->request('GET', '/settings/prices/');

        self::assertResponseIsSuccessful();
        $names = $crawler->filter('.card tbody tr td:first-child')->each(static fn ($cell): string => trim($cell->text()));
        $ours = array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, self::PREFIX)));
        self::assertSame([self::PREFIX.'overview-a', self::PREFIX.'overview-b'], $ours);
    }

    /** Removes the prices created by these examples from the prepared test database. */
    public function testSurchargeForFurtherPersonsIsSavedForAPerRoomPrice(): void
    {
        $client = $this->authenticatedClient();
        [$single] = $this->categoryIds();

        $this->postPrice($client, 'surcharge', 2, [$single], overrides: ['extra-person-surcharge-new' => '15,50']);

        self::assertResponseIsSuccessful();
        $price = $this->findPrice('surcharge');
        self::assertSame('15.50', $price?->getExtraPersonSurcharge());

        // Elsewhere the number of persons is matched exactly, so form and list say "up to".
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $form = $client->request('GET', sprintf('/settings/prices/%d/get', $price->getId()));
        self::assertSame($translator->trans('price.numberofpersons.scope.up_to'), trim($form->filter('#number-of-persons-scope-'.$price->getId())->text()));
        $list = $client->request('GET', '/settings/prices/');
        self::assertStringContainsString($translator->trans('price.numberofpersons.up_to_count', ['%count%' => self::PERSONS]), $list->filter('body')->text());
    }

    public function testSurchargeIsDroppedForAPricePerPerson(): void
    {
        $client = $this->authenticatedClient();
        [$single] = $this->categoryIds();

        $this->postPrice($client, 'per-person', 2, [$single], overrides: [
            'calculation-type-new' => 'per_person',
            'extra-person-surcharge-new' => '15',
        ]);

        self::assertResponseIsSuccessful();
        $price = $this->findPrice('per-person');
        self::assertNotNull($price);
        self::assertNull($price->getExtraPersonSurcharge());
    }

    public function testASurchargePriceServesEveryPartyUntilAnExactPriceExists(): void
    {
        $client = $this->authenticatedClient();
        [$single] = $this->categoryIds();
        $this->postPrice($client, 'base', 2, [$single], overrides: ['extra-person-surcharge-new' => '15']);

        self::assertSame([self::PREFIX.'base'], $this->apartmentPriceDescriptions($single, self::PERSONS + 2));
        // The base price includes up to its number of persons, so a smaller party pays it as well.
        self::assertSame([self::PREFIX.'base'], $this->apartmentPriceDescriptions($single, self::PERSONS - 1));

        $this->postPrice($client, 'exact', 2, [$single], overrides: ['number-of-persons-new' => (string) (self::PERSONS + 2)]);
        $this->postPrice($client, 'exact-small', 2, [$single], overrides: ['number-of-persons-new' => (string) (self::PERSONS - 1)]);

        self::assertSame([self::PREFIX.'exact', self::PREFIX.'base'], $this->apartmentPriceDescriptions($single, self::PERSONS + 2));
        self::assertSame([self::PREFIX.'exact-small', self::PREFIX.'base'], $this->apartmentPriceDescriptions($single, self::PERSONS - 1));
    }

    public function testTheClosestSurchargePriceBelowThePartyComesBeforeOneAbove(): void
    {
        $client = $this->authenticatedClient();
        [$single] = $this->categoryIds();
        $this->postPrice($client, 'small', 2, [$single], overrides: ['extra-person-surcharge-new' => '15']);
        $this->postPrice($client, 'large', 2, [$single], overrides: [
            'extra-person-surcharge-new' => '5',
            'number-of-persons-new' => (string) (self::PERSONS + 4),
        ]);

        // Between both bases the party exceeds the small one and pays its surcharge.
        self::assertSame([self::PREFIX.'small', self::PREFIX.'large'], $this->apartmentPriceDescriptions($single, self::PERSONS + 1));
        self::assertSame([self::PREFIX.'large', self::PREFIX.'small'], $this->apartmentPriceDescriptions($single, self::PERSONS + 5));
        // Below both bases the closest one applies.
        self::assertSame([self::PREFIX.'small', self::PREFIX.'large'], $this->apartmentPriceDescriptions($single, self::PERSONS - 1));
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $em = $this->em();
            if ($em->isOpen()) {
                foreach ($em->getRepository(Price::class)->findAll() as $price) {
                    if (str_starts_with((string) $price->getDescription(), self::PREFIX)) {
                        $em->remove($price);
                    }
                }
                $em->flush();
            }
        }
        parent::tearDown();
    }

    /**
     * Submits the price form the way the settings modal does. Without $id a new price is created,
     * otherwise the existing one is edited.
     *
     * @param list<int> $categoryIds
     * @param array<string, mixed> $overrides extra or replaced form fields
     */
    private function postPrice(KernelBrowser $client, string $name, int $type, array $categoryIds, string $id = 'new', array $overrides = []): \Symfony\Component\DomCrawler\Crawler
    {
        // The legacy CSRF token lives in the session and is created by rendering the form.
        $form = $client->request('GET', '/settings/prices/new');
        $token = $form->filter('input[name="_csrf_token"]')->attr('value');

        $origin = $this->em()->getRepository(ReservationOrigin::class)->findOneBy([]);
        $url = 'new' === $id ? '/settings/prices/create' : sprintf('/settings/prices/%s/edit', $id);

        return $client->request('POST', $url, array_merge([
            '_csrf_token' => $token,
            'description-'.$id => self::PREFIX.$name,
            'price-'.$id => '80,00',
            'vat-'.$id => '7',
            'type-'.$id => (string) $type,
            'origin-'.$id => [(string) $origin?->getId()],
            'category-'.$id => array_map(strval(...), $categoryIds),
            'calculation-type-'.$id => 'per_room',
            'active-'.$id => '1',
            'alldays-'.$id => '1',
            'allperiods-'.$id => '1',
            'number-of-persons-'.$id => (string) self::PERSONS,
            'min-stay-'.$id => (string) self::MIN_STAY,
        ], $overrides));
    }

    /**
     * Descriptions of the misc prices a reservation in a room of the given category would get.
     *
     * @return list<string>
     */
    private function miscPriceDescriptions(int $categoryId): array
    {
        $em = $this->em();
        $reservation = new Reservation();
        $reservation->setAppartment($em->getRepository(Appartment::class)->findOneBy(['roomCategory' => $categoryId]));
        $reservation->setReservationOrigin($em->getRepository(ReservationOrigin::class)->findOneBy([]));
        $reservation->setStartDate(new \DateTime('+400 days'));
        $reservation->setEndDate(new \DateTime('+403 days'));

        return array_map(
            static fn (Price $price): string => (string) $price->getDescription(),
            self::getContainer()->get(PriceRepository::class)->findMiscPrices($reservation),
        );
    }

    /**
     * Descriptions of the room prices, in priority order, for a party in a room of the category.
     *
     * @return list<string>
     */
    private function apartmentPriceDescriptions(int $categoryId, int $persons): array
    {
        $em = $this->em();
        $em->clear();
        $reservation = new Reservation();
        $reservation->setAppartment($em->getRepository(Appartment::class)->findOneBy(['roomCategory' => $categoryId]));
        $reservation->setReservationOrigin($em->getRepository(ReservationOrigin::class)->findOneBy([]));
        $reservation->setPersons($persons);
        $reservation->setStartDate(new \DateTime('+400 days'));
        $reservation->setEndDate(new \DateTime('+413 days'));

        return array_values(array_filter(
            array_map(
                static fn (Price $price): string => (string) $price->getDescription(),
                self::getContainer()->get(PriceRepository::class)->findApartmentPrices($reservation, self::MIN_STAY),
            ),
            static fn (string $description): bool => str_starts_with($description, self::PREFIX),
        ));
    }

    /** @return array{0: int, 1: int} ids of the two sample room categories */
    private function categoryIds(): array
    {
        $ids = array_map(
            static fn (RoomCategory $category): int => (int) $category->getId(),
            $this->em()->getRepository(RoomCategory::class)->findBy([], ['id' => 'ASC'], 2),
        );
        self::assertCount(2, $ids, 'Sample data must contain two room categories.');

        return [$ids[0], $ids[1]];
    }

    private function findPrice(string $name): ?Price
    {
        $em = $this->em();
        // Requests run in their own kernel; drop stale copies before reading.
        $em->clear();

        return $em->getRepository(Price::class)->findOneBy(['description' => self::PREFIX.$name]);
    }

    /** @return list<int> */
    private function idsOf(?Price $price): array
    {
        self::assertNotNull($price);
        $ids = $price->getRoomCategories()->map(static fn (RoomCategory $category): int => (int) $category->getId())->getValues();
        sort($ids);

        return $ids;
    }

    private function authenticatedClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser($this->admin(), 'main');

        return $client;
    }

    private function admin(): User
    {
        return $this->em()->getRepository(User::class)->findOneBy(['username' => 'test-admin']) ?? throw new \RuntimeException('Prepared test admin missing.');
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
