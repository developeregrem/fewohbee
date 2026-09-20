<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\RoomCategory;
use App\Entity\User;
use App\Repository\PriceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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

    public function testRoomPriceWithoutCategoryIsRejected(): void
    {
        $client = $this->authenticatedClient();

        $crawler = $this->postPrice($client, 'no-category', 2, []);

        self::assertCount(1, $crawler->filter('.alert'));
        self::assertNull($this->findPrice('no-category'));
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

    /** Removes the prices created by these examples from the prepared test database. */
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
     */
    private function postPrice(KernelBrowser $client, string $name, int $type, array $categoryIds, string $id = 'new'): \Symfony\Component\DomCrawler\Crawler
    {
        // The legacy CSRF token lives in the session and is created by rendering the form.
        $form = $client->request('GET', '/settings/prices/new');
        $token = $form->filter('input[name="_csrf_token"]')->attr('value');

        $origin = $this->em()->getRepository(ReservationOrigin::class)->findOneBy([]);
        $url = 'new' === $id ? '/settings/prices/create' : sprintf('/settings/prices/%s/edit', $id);

        return $client->request('POST', $url, [
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
        ]);
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
