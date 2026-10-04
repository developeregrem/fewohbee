<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\DayPrice;
use App\Entity\Price;
use App\Entity\ReservationOrigin;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\PriceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PriceCalendarControllerTest extends WebTestCase
{
    public function testADayPriceIsSetAndRemovedFromTheCalendar(): void
    {
        $client = $this->adminClient();
        // Far ahead, so no other test prices one of these nights.
        $month = new \DateTimeImmutable('first day of +30 months midnight');
        $night = $month->modify('+9 days');

        try {
            $form = $this->openNight($client, $month, $night);
            $listed = (float) $form->filter('input[name="amount"]')->attr('value');
            self::assertGreaterThan(0.0, $listed);

            $client->request('POST', '/settings/prices/calendar/night', $this->fields($form, ['amount' => (string) ($listed + 20), 'intent' => 'save']));
            self::assertResponseStatusCodeSame(204);
            self::assertSame([$night->format('Y-m-d') => $listed + 20], $this->dayPrices());

            // The night now costs exactly the day price, and says where it comes from.
            $form = $this->openNight($client, $month, $night);
            self::assertSame(number_format($listed + 20, 2, '.', ''), $form->filter('input[name="amount"]')->attr('value'));
            self::assertCount(1, $form->filter('button[value="reset"]'));

            $client->request('POST', '/settings/prices/calendar/night', $this->fields($form, ['intent' => 'reset']));
            self::assertResponseStatusCodeSame(204);
            self::assertSame([], $this->dayPrices());
        } finally {
            $this->removeDayPrices();
        }
    }

    public function testARangeOnlyCoversTheSelectedWeekdays(): void
    {
        $client = $this->adminClient();
        $month = new \DateTimeImmutable('first day of +31 months midnight');
        $night = $month->modify('+2 days');

        try {
            $form = $this->openNight($client, $month, $night);
            $client->request('POST', '/settings/prices/calendar/night', $this->fields($form, [
                'amount' => '199',
                'intent' => 'save',
                'last' => $night->modify('+6 days')->format('Y-m-d'),
                'weekdays' => ['6'],
            ]));
            self::assertResponseStatusCodeSame(204);

            $nights = array_keys($this->dayPrices());
            self::assertCount(1, $nights);
            self::assertSame('6', (new \DateTimeImmutable($nights[0]))->format('N'));
        } finally {
            $this->removeDayPrices();
        }
    }

    public function testTheNightListsEveryOccupancyOfTheCategory(): void
    {
        $client = $this->adminClient();
        $month = new \DateTimeImmutable('first day of +30 months midnight');
        $room = $this->em()->getRepository(Appartment::class)->findOneBy(['active' => true], ['id' => 'ASC']);
        $category = $room?->getRoomCategory() ?? self::fail('Sample data must contain an active room with a category.');
        $occupancies = static::getContainer()->get(PriceRepository::class)->findOccupanciesForRoomCategory($category);
        // A second occupancy for the category, priced per room.
        $price = new Price();
        $price->setType(2);
        $price->setActive(true);
        $price->setAllDays(true);
        $price->setAllPeriods(true);
        $price->setVat(7);
        $price->setPrice(150);
        $price->setDescription('Occupancy test');
        $price->setIsPerRoom(true);
        $price->setNumberOfPersons(max($occupancies) + 1);
        $price->setMinStay(1);
        $price->addRoomCategory($category);
        foreach ($this->em()->getRepository(ReservationOrigin::class)->findAll() as $origin) {
            $price->addReservationOrigin($origin);
        }
        $this->em()->persist($price);
        $this->em()->flush();

        try {
            $this->openNight($client, $month, $month->modify('+4 days'));
            $rows = $client->getCrawler()->filter('table[data-price-calendar-occupancies] tbody tr');

            self::assertSame(
                array_map('strval', [...$occupancies, max($occupancies) + 1]),
                $rows->each(static fn (Crawler $row): string => (string) $row->attr('data-persons')),
            );
            // The preview needs the unit price of every occupancy.
            self::assertSame('150', $rows->last()->attr('data-base-unit'));
        } finally {
            $this->em()->remove($this->em()->find(Price::class, $price->getId()) ?? self::fail('Price vanished.'));
            $this->em()->flush();
        }
    }

    public function testAnInvalidAmountIsRefused(): void
    {
        $client = $this->adminClient();
        $month = new \DateTimeImmutable('first day of +30 months midnight');
        $form = $this->openNight($client, $month, $month->modify('+3 days'));

        $client->request('POST', '/settings/prices/calendar/night', $this->fields($form, ['amount' => '0', 'intent' => 'save']));
        self::assertResponseStatusCodeSame(422);

        $client->request('POST', '/settings/prices/calendar/night', $this->fields($form, ['_token' => 'forged', 'amount' => '99', 'intent' => 'save']));
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->dayPrices());
    }

    public function testTheTabShowsTheLastSelectionAgain(): void
    {
        $client = $this->adminClient();
        $month = new \DateTimeImmutable('first day of +5 months midnight');
        $client->request('GET', '/settings/prices/calendar/?month='.$month->format('Y-m'));

        // The tab link carries no selection.
        $crawler = $client->request('GET', '/settings/prices/calendar/');

        self::assertSame($month->format('Y-m'), $crawler->filter('input[name="month"]')->attr('value'));
    }

    public function testStaffWithoutAdministrationCannotOpenThePriceCalendar(): void
    {
        $client = self::createClient();
        $client->loginUser($this->reservationsUser(), 'main');

        $client->request('GET', '/settings/prices/calendar/');

        self::assertResponseStatusCodeSame(403);
    }

    /** Opens the calendar month and the night's offcanvas; returns the day price form. */
    private function openNight(KernelBrowser $client, \DateTimeImmutable $month, \DateTimeImmutable $night): Crawler
    {
        // The first active room decides the selection; other tests may add subsidiaries without rooms.
        $room = $this->em()->getRepository(Appartment::class)->findOneBy(['active' => true], ['id' => 'ASC'])
            ?? throw new \RuntimeException('Sample data must contain an active room.');
        $calendar = $client->request('GET', '/settings/prices/calendar/?'.http_build_query([
            'subsidiary' => $room->getObject()?->getId(),
            'category' => $room->getRoomCategory()?->getId(),
            'month' => $month->format('Y-m'),
        ]));
        self::assertResponseIsSuccessful();
        $button = $calendar->filter(sprintf('.list-group [data-url*="night=%s"]', $night->format('Y-m-d')));
        self::assertCount(1, $button, 'The calendar must offer the night.');

        $client->request('GET', (string) $button->attr('data-url'));
        self::assertResponseIsSuccessful();
        $form = $client->getCrawler()->filter('form');
        self::assertCount(1, $form, 'The night must have a price to change.');

        return $form;
    }

    /**
     * @param array<string, string|list<string>> $overrides
     *
     * @return array<string, mixed>
     */
    private function fields(Crawler $form, array $overrides): array
    {
        $fields = [];
        foreach (['_token', 'subsidiary', 'category', 'persons', 'night', 'amount', 'last'] as $name) {
            $fields[$name] = (string) $form->filter(sprintf('input[name="%s"]', $name))->attr('value');
        }
        $fields['weekdays'] = array_map('strval', range(1, 7));

        return array_merge($fields, $overrides);
    }

    /** @return array<string, float> */
    private function dayPrices(): array
    {
        $this->em()->clear();
        $result = [];
        foreach ($this->em()->getRepository(DayPrice::class)->findAll() as $dayPrice) {
            $result[$dayPrice->getNight()->format('Y-m-d')] = $dayPrice->getAmount();
        }

        return $result;
    }

    private function removeDayPrices(): void
    {
        $this->em()->createQuery('DELETE FROM App\Entity\DayPrice d')->execute();
    }

    private function adminClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser($this->em()->getRepository(User::class)->findOneBy(['username' => 'test-admin'])
            ?? throw new \RuntimeException('Prepared test admin missing.'), 'main');

        return $client;
    }

    private function reservationsUser(): User
    {
        $em = $this->em();
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'price-calendar-staff']);
        if ($user instanceof User) {
            return $user;
        }
        $role = $em->getRepository(Role::class)->findOneBy(['role' => 'ROLE_RESERVATIONS'])
            ?? throw new \RuntimeException('Role ROLE_RESERVATIONS missing.');
        $user = new User();
        $user->setUsername('price-calendar-staff');
        $user->setFirstname('Price');
        $user->setLastname('Staff');
        $user->setEmail('price-calendar-staff@example.com');
        $user->setPassword('unused');
        $user->setActive(true);
        $user->setRoleEntities([$role]);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
