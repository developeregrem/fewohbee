<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\DayPrice;
use App\Entity\Enum\DayPriceSource;
use App\Entity\Enum\PercentageBase;
use App\Entity\Enum\TaxCalculationMode;
use App\Entity\Price;
use App\Entity\PriceRule;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Entity\TouristTax;
use App\Entity\User;
use App\Service\InvoiceService;
use App\Service\Pricing\PricePromiseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Bookings keep the price they were booked at when the price list changes afterwards.
 */
final class ReservationPricePromiseTest extends WebTestCase
{
    // An occupancy no sample price row uses, so the row created here is the only one that applies.
    private const PERSONS = 8;

    public function testPriceListChangesDoNotReachExistingBookings(): void
    {
        $client = $this->authenticatedClient();
        $price = $this->createRoomPrice('80.00');
        $promised = $this->createReservation('+800 days', withPromise: true);
        // A booking from before the update, without a promise yet: it must be promised the
        // current price before the change is saved.
        $legacy = $this->createReservation('+810 days', withPromise: false);

        $this->editRoomPrice($client, $price, '95,00');
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        self::assertSame(95.0, (float) $this->em()->find(Price::class, $price->getId())?->getPrice());
        foreach ([$promised, $legacy] as $reservation) {
            $booked = $this->em()->find(Reservation::class, $reservation->getId());
            self::assertInstanceOf(Reservation::class, $booked);
            self::assertNotNull($booked->getPricePromise());
            $positions = self::getContainer()->get(InvoiceService::class)->buildAppartmentPositions($booked);
            self::assertCount(1, $positions);
            self::assertSame('80.00', (string) $positions[0]->getPrice());
        }

        // A new booking is priced from the changed list.
        $new = $this->createReservation('+820 days', withPromise: true);
        $positions = self::getContainer()->get(InvoiceService::class)->buildAppartmentPositions($new);
        self::assertSame('95.00', (string) $positions[0]->getPrice());
    }

    public function testThePriceTabOnlyOffersTodaysPricesWhenThePriceListChanged(): void
    {
        $client = $this->authenticatedClient();
        $price = $this->createRoomPrice('80.00');
        $extra = $this->createExtra('12.00');
        $reservation = $this->createReservation('+830 days', withPromise: true);
        $tax = $this->createPercentageTouristTax($reservation);
        $viewUrl = sprintf('/reservation/get/%d?tab=prices', $reservation->getId());

        try {
            // Adding an extra raises the total but needs no decision: it is priced today anyway.
            $crawler = $client->request('GET', $viewUrl);
            self::assertCount(0, $crawler->filter('form[action$="/price/reprice"]'));
            $toggle = $crawler->filter(sprintf('#update-misc-price-%d', $extra->getId()));
            $client->request('POST', (string) $toggle->attr('action'), ['_token' => $toggle->filter('input[name="_token"]')->attr('value')]);
            $crawler = $client->request('GET', $viewUrl);
            self::assertCount(0, $crawler->filter('form[action$="/price/reprice"]'));
            self::assertCount(0, $crawler->filter('a[href="#prices"] .badge'));

            $this->em()->find(Price::class, $price->getId())?->setPrice('99.00');
            $this->em()->flush();

            $crawler = $client->request('GET', $viewUrl);
            self::assertCount(1, $crawler->filter('a[href="#prices"] .badge'));
            $notice = $crawler->filter('.alert:has(form[action$="/price/reprice"])');
            self::assertCount(1, $notice);
            // The same totals the tab shows: 2 nights per room, the extra for 8 guests on
            // 2 nights and the tourist tax of 10 % on the room, which follows the room price.
            self::assertStringContainsString('368,00', $notice->text());
            self::assertStringContainsString('409,80', $notice->text());
            $token = $notice->filter('input[name="_token"]')->attr('value');

            $client->request('POST', sprintf('/reservation/%d/price/reprice', $reservation->getId()), ['_token' => 'forged']);
            $this->em()->clear();
            self::assertSame('80.00', $this->promisedUnit($reservation));

            $crawler = $client->request('POST', sprintf('/reservation/%d/price/reprice', $reservation->getId()), ['_token' => $token]);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('form[action$="/price/reprice"]'));
            self::assertCount(0, $crawler->filter('a[href="#prices"] .badge'));
            $this->em()->clear();
            self::assertSame('99.00', $this->promisedUnit($reservation));
        } finally {
            $this->em()->remove($this->em()->find(TouristTax::class, $tax->getId()));
            $this->em()->flush();
        }
    }

    public function testANewBookingIsPromisedThePriceAfterPriceRules(): void
    {
        $this->createRoomPrice('80.00');
        $arrival = new \DateTimeImmutable('+860 days');
        $rule = new PriceRule();
        $rule->setName('Messe');
        $rule->setPercent(10.0);
        $rule->setPeriod($arrival->setTime(0, 0), $arrival->setTime(0, 0)->modify('+2 days'));
        $this->em()->persist($rule);
        $this->em()->flush();

        try {
            $reservation = $this->createReservation('+860 days', withPromise: true);

            $night = $reservation->getPricePromise()['n'][0] ?? [];
            self::assertSame('88.00', $night['u'] ?? null);
            self::assertSame('80.00', $night['d']['b'] ?? null);
            self::assertSame([['Messe', '10.00']], $night['d']['r'] ?? null);
        } finally {
            $this->em()->remove($this->em()->find(PriceRule::class, $rule->getId()));
            $this->em()->flush();
        }
    }

    public function testANewBookingIsPromisedTheDayPriceOfItsNight(): void
    {
        $this->createRoomPrice('80.00');
        $arrival = (new \DateTimeImmutable('+870 days'))->setTime(0, 0);
        $room = $this->apartment();
        $dayPrice = new DayPrice($room->getObject(), $room->getRoomCategory(), $arrival);
        $dayPrice->set(120.0, self::PERSONS, DayPriceSource::MANUAL, null, new \DateTimeImmutable());
        $this->em()->persist($dayPrice);
        $this->em()->flush();

        try {
            $reservation = $this->createReservation('+870 days', withPromise: true);

            $nights = $reservation->getPricePromise()['n'] ?? [];
            self::assertSame('120.00', $nights[0]['u'] ?? null);
            self::assertSame('manual', $nights[0]['d']['s'] ?? null);
            // The second night has no day price and keeps the price list.
            self::assertSame('80.00', $nights[1]['u'] ?? null);
        } finally {
            $this->em()->remove($this->em()->find(DayPrice::class, $dayPrice->getId()));
            $this->em()->flush();
        }
    }

    public function testAPriceStillPromisedToABookingIsSwitchedOffInsteadOfDeleted(): void
    {
        $client = $this->authenticatedClient();
        $price = $this->createRoomPrice('80.00');
        $this->createReservation('+840 days', withPromise: true);

        $crawler = $client->request('GET', '/settings/prices/');
        $popover = $crawler->filter(sprintf('[data-bs-content*="/settings/prices/%d/delete"]', $price->getId()));
        self::assertCount(1, $popover);
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', (string) $popover->attr('data-bs-content'), $match));

        $client->request('DELETE', sprintf('/settings/prices/%d/delete', $price->getId()), ['_token' => $match[1]]);

        $this->em()->clear();
        $kept = $this->em()->find(Price::class, $price->getId());
        self::assertInstanceOf(Price::class, $kept);
        self::assertFalse((bool) $kept->getActive());
    }

    private function promisedUnit(Reservation $reservation): ?string
    {
        $booked = $this->em()->find(Reservation::class, $reservation->getId());

        return $booked?->getPricePromise()['n'][0]['u'] ?? null;
    }

    private function editRoomPrice(KernelBrowser $client, Price $price, string $amount): void
    {
        // The legacy CSRF token lives in the session and is created by rendering the form.
        $form = $client->request('GET', '/settings/prices/new');
        $token = $form->filter('input[name="_csrf_token"]')->attr('value');
        $id = (string) $price->getId();

        $client->request('POST', sprintf('/settings/prices/%s/edit', $id), [
            '_csrf_token' => $token,
            'description-'.$id => (string) $price->getDescription(),
            'price-'.$id => $amount,
            'vat-'.$id => '7',
            'type-'.$id => '2',
            'origin-'.$id => [(string) $this->origin()->getId()],
            'category-'.$id => [(string) $this->apartment()->getRoomCategory()?->getId()],
            'calculation-type-'.$id => 'per_room',
            'active-'.$id => '1',
            'alldays-'.$id => '1',
            'allperiods-'.$id => '1',
            'number-of-persons-'.$id => (string) self::PERSONS,
            'min-stay-'.$id => '1',
        ]);
    }

    private function createRoomPrice(string $amount): Price
    {
        $price = new Price();
        $price->setType(2);
        $price->setDescription('promise-test '.bin2hex(random_bytes(3)));
        $price->setPrice($amount);
        $price->setVat(7);
        $price->setIncludesVat(true);
        $price->setIsPerRoom(true);
        $price->setIsFlatPrice(false);
        $price->setNumberOfPersons(self::PERSONS);
        $price->setMinStay(1);
        $price->setActive(true);
        $price->setAllDays(true);
        $price->setAllPeriods(true);
        $price->addReservationOrigin($this->origin());
        $price->addRoomCategory($this->apartment()->getRoomCategory());
        // Rows of earlier tests in this class would compete for the same nights.
        foreach ($this->em()->getRepository(Price::class)->findBy(['type' => 2, 'numberOfPersons' => self::PERSONS, 'minStay' => 1]) as $old) {
            $old->setActive(false);
        }
        $this->em()->persist($price);
        $this->em()->flush();

        return $price;
    }

    /** A tax that only applies to the nights of this reservation, so other tests are not affected. */
    private function createPercentageTouristTax(Reservation $reservation): TouristTax
    {
        $tax = new TouristTax();
        $tax->setName('promise-test tax');
        $tax->setCalculationMode(TaxCalculationMode::PERCENT_PER_ROOM);
        $tax->setPercentageRate('10.00');
        $tax->setPercentageBase(PercentageBase::GROSS);
        $tax->setIncludesVat(true);
        $tax->setValidFrom(clone $reservation->getStartDate());
        $tax->setValidTo(clone $reservation->getEndDate());
        $this->em()->persist($tax);
        $this->em()->flush();

        return $tax;
    }

    private function createExtra(string $amount): Price
    {
        $extra = new Price();
        $extra->setType(1);
        $extra->setDescription('promise-test extra '.bin2hex(random_bytes(3)));
        $extra->setPrice($amount);
        $extra->setVat(7);
        $extra->setIncludesVat(true);
        $extra->setIsPerRoom(false);
        $extra->setIsFlatPrice(false);
        $extra->setActive(true);
        $extra->setAllDays(true);
        $extra->setAllPeriods(true);
        $extra->addReservationOrigin($this->origin());
        $this->em()->persist($extra);
        $this->em()->flush();

        return $extra;
    }

    private function createReservation(string $arrival, bool $withPromise): Reservation
    {
        $start = new \DateTime($arrival);
        $reservation = new Reservation();
        $reservation->setAppartment($this->apartment());
        $reservation->setReservationOrigin($this->origin());
        $reservation->setReservationStatus($this->em()->getRepository(ReservationStatus::class)->findOneBy([], ['id' => 'ASC']));
        $reservation->setPersons(self::PERSONS);
        $reservation->setStartDate($start);
        $reservation->setEndDate((clone $start)->modify('+2 days'));
        $reservation->setUuid(Uuid::v4());
        if ($withPromise) {
            self::getContainer()->get(PricePromiseService::class)->reconcile($reservation);
        }
        $this->em()->persist($reservation);
        $this->em()->flush();

        return $reservation;
    }

    private function apartment(): Appartment
    {
        return $this->em()->getRepository(Appartment::class)->findOneBy(['active' => true], ['id' => 'ASC'])
            ?? throw new \RuntimeException('Sample data must contain an active room.');
    }

    private function origin(): ReservationOrigin
    {
        return $this->em()->getRepository(ReservationOrigin::class)->findOneBy([], ['id' => 'ASC'])
            ?? throw new \RuntimeException('Sample data must contain a reservation origin.');
    }

    private function authenticatedClient(): KernelBrowser
    {
        $client = self::createClient();
        $admin = $this->em()->getRepository(User::class)->findOneBy(['username' => 'test-admin'])
            ?? throw new \RuntimeException('Prepared test admin missing.');
        $client->loginUser($admin, 'main');

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
