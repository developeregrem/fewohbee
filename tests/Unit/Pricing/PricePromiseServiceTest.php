<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Dto\PriceBreakdown;
use App\Dto\Pricing\NightRate;
use App\Dto\Pricing\PricePromise;
use App\Entity\Appartment;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Repository\ReservationRepository;
use App\Service\InvoiceService;
use App\Service\PriceService;
use App\Service\Pricing\PricePromiseService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class PricePromiseServiceTest extends TestCase
{
    public function testANewBookingIsPromisedTodaysPrices(): void
    {
        $room = $this->price(17, '80.00');
        $breakfast = $this->price(5, '12.50');
        $reservation = $this->reservation('2026-12-20', '2026-12-22');
        $reservation->addPrice($breakfast);

        $this->service([$room, $room], [$room, $breakfast])->reconcile($reservation);

        $promise = PricePromise::fromArray($reservation->getPricePromise());
        self::assertNotNull($promise);
        self::assertSame('2026-10-02', $promise->promisedOn);
        self::assertSame('80.00', $promise->night(new \DateTimeImmutable('2026-12-20'))?->unit);
        self::assertSame('80.00', $promise->night(new \DateTimeImmutable('2026-12-21'))?->unit);
        self::assertNull($promise->night(new \DateTimeImmutable('2026-12-22')));
        self::assertSame('12.50', $promise->extra(5)?->unit);
        self::assertCount(1, $reservation->getPricePromise()['n'], 'two equal nights form one segment');
    }

    public function testAnExtendedStayKeepsThePromisedNightsAndPricesTheNewOnesToday(): void
    {
        $room = $this->price(17, '80.00');
        $reservation = $this->reservation('2026-12-20', '2026-12-22');
        $this->service([$room, $room], [$room], '2026-09-01')->reconcile($reservation);

        $room->setPrice('95.00');
        $reservation->setEndDate(new \DateTime('2026-12-23'));
        $this->service([$room, $room, $room], [$room])->reconcile($reservation);

        $promise = PricePromise::fromArray($reservation->getPricePromise());
        self::assertSame('2026-09-01', $promise?->promisedOn);
        self::assertSame('80.00', $promise->night(new \DateTimeImmutable('2026-12-21'))?->unit);
        self::assertSame('95.00', $promise->night(new \DateTimeImmutable('2026-12-22'))?->unit);
    }

    public function testOtherGuestsArePricedAnewForEveryNight(): void
    {
        $room = $this->price(17, '80.00');
        $reservation = $this->reservation('2026-12-20', '2026-12-22');
        $this->service([$room, $room], [$room], '2026-09-01')->reconcile($reservation);

        $room->setPrice('95.00');
        $reservation->setPersons(3);
        $reservation->setGuestCounts([1 => 3]);
        $this->service([$room, $room], [$room])->reconcile($reservation);

        $promise = PricePromise::fromArray($reservation->getPricePromise());
        self::assertSame('2026-10-02', $promise?->promisedOn);
        self::assertSame('95.00', $promise->night(new \DateTimeImmutable('2026-12-20'))?->unit);
    }

    public function testRepriceAllGivesUpThePromise(): void
    {
        $room = $this->price(17, '80.00');
        $breakfast = $this->price(5, '12.50');
        $reservation = $this->reservation('2026-12-20', '2026-12-22');
        $reservation->addPrice($breakfast);
        $this->service([$room, $room], [$room, $breakfast], '2026-09-01')->reconcile($reservation);

        $room->setPrice('95.00');
        $breakfast->setPrice('14.00');
        $this->service([$room, $room], [$room, $breakfast])->reconcile($reservation, repriceAll: true);

        $promise = PricePromise::fromArray($reservation->getPricePromise());
        self::assertSame('95.00', $promise?->night(new \DateTimeImmutable('2026-12-20'))?->unit);
        self::assertSame('14.00', $promise->extra(5)?->unit);
    }

    public function testBookedExtrasKeepTheirPriceWhileNewExtrasCostTodaysPrice(): void
    {
        $room = $this->price(17, '80.00');
        $breakfast = $this->price(5, '12.50');
        $dog = $this->price(6, '10.00');
        $reservation = $this->reservation('2026-12-20', '2026-12-22');
        $reservation->addPrice($breakfast);
        $this->service([$room, $room], [$room, $breakfast])->reconcile($reservation);

        $breakfast->setPrice('14.00');
        $dog->setPrice('11.00');
        $reservation->addPrice($dog);
        $this->service([$room, $room], [$room, $breakfast, $dog])->reconcile($reservation);

        $promise = PricePromise::fromArray($reservation->getPricePromise());
        self::assertSame('12.50', $promise?->extra(5)?->unit);
        self::assertSame('11.00', $promise->extra(6)?->unit);
    }

    public function testAnInactiveExtraWithoutPromiseIsNotPromised(): void
    {
        $room = $this->price(17, '80.00');
        $retired = $this->price(5, '12.50');
        $retired->setActive(false);
        $reservation = $this->reservation('2026-12-20', '2026-12-22');
        $reservation->addPrice($retired);

        $this->service([$room, $room], [$room])->reconcile($reservation);

        self::assertNull(PricePromise::fromArray($reservation->getPricePromise())?->extra(5));
    }

    public function testReconcilingAnUnchangedBookingChangesNothing(): void
    {
        $room = $this->price(17, '80.00');
        $reservation = $this->reservation('2026-12-20', '2026-12-22');
        $this->service([$room, $room], [$room], '2026-09-01')->reconcile($reservation);
        $first = $reservation->getPricePromise();

        $this->service([$room, $room], [$room])->reconcile($reservation);

        self::assertSame($first, $reservation->getPricePromise());
    }

    public function testAPromisedNightWhosePriceRowIsGoneIsPricedToday(): void
    {
        $room = $this->price(17, '80.00');
        $successor = $this->price(18, '90.00');
        $reservation = $this->reservation('2026-12-20', '2026-12-21');
        $this->service([$room], [$room])->reconcile($reservation);

        $this->service([$successor], [$successor])->reconcile($reservation);

        self::assertSame(18, PricePromise::fromArray($reservation->getPricePromise())?->night(new \DateTimeImmutable('2026-12-20'))?->priceId);
    }

    public function testABookingWithoutOriginGetsAnEmptyPromise(): void
    {
        $reservation = $this->reservation('2026-12-20', '2026-12-22');
        $reservation->setReservationOrigin(null);

        $this->service([], [])->reconcile($reservation);

        $promise = PricePromise::fromArray($reservation->getPricePromise());
        self::assertNotNull($promise);
        self::assertSame([], $promise->nights);
        self::assertSame([], $promise->extras);
    }

    /**
     * @param list<Price> $nightPrices live price row per night, starting at the arrival night
     * @param list<Price> $existing    price rows that still exist
     */
    private function service(array $nightPrices, array $existing, string $today = '2026-10-02'): PricePromiseService
    {
        $priceService = $this->createStub(PriceService::class);
        $priceService->method('promiseOf')->willReturnCallback(
            static fn (Reservation $r): ?PricePromise => PricePromise::fromArray($r->getPricePromise())
        );
        $byId = [];
        foreach ($existing as $price) {
            $byId[(int) $price->getId()] = $price;
        }
        $priceService->method('findPricesById')->willReturnCallback(
            static fn (array $ids): array => array_intersect_key($byId, array_flip($ids))
        );
        $priceService->method('getNightRates')->willReturnCallback(
            static function (Reservation $r) use ($nightPrices): array {
                $first = \DateTimeImmutable::createFromInterface($r->getStartDate());

                return array_map(
                    static fn (Price $price, int $i): NightRate => NightRate::live($first->modify('+'.$i.' day'), $price),
                    $nightPrices,
                    array_keys($nightPrices),
                );
            }
        );
        $priceService->method('getPriceBreakdownForReservation')->willReturnCallback(
            static function (Reservation $r) use ($nightPrices): array {
                $first = \DateTimeImmutable::createFromInterface($r->getStartDate());

                return array_map(
                    static fn (Price $price, int $i): PriceBreakdown => new PriceBreakdown($first->modify('+'.$i.' day'), $price),
                    $nightPrices,
                    array_keys($nightPrices),
                );
            }
        );

        return new PricePromiseService(
            $priceService,
            $this->createStub(InvoiceService::class),
            new MockClock($today.' 10:00:00'),
            $this->createStub(ReservationRepository::class),
            new NullLogger(),
        );
    }

    private function price(int $id, string $amount): Price
    {
        $price = new Price();
        $price->setId($id);
        $price->setPrice($amount);
        $price->setIncludesVat(true);
        $price->setIsFlatPrice(false);
        $price->setIsPerRoom(false);
        $price->setActive(true);

        return $price;
    }

    private function reservation(string $arrival, string $departure): Reservation
    {
        $category = new RoomCategory();
        (new \ReflectionProperty(RoomCategory::class, 'id'))->setValue($category, 3);
        $subsidiary = new Subsidiary();
        (new \ReflectionProperty(Subsidiary::class, 'id'))->setValue($subsidiary, 1);
        $apartment = new Appartment();
        $apartment->setRoomCategory($category);
        $apartment->setObject($subsidiary);
        $origin = new ReservationOrigin();
        (new \ReflectionProperty(ReservationOrigin::class, 'id'))->setValue($origin, 2);

        $reservation = new Reservation();
        $reservation->setAppartment($apartment);
        $reservation->setReservationOrigin($origin);
        $reservation->setPersons(2);
        $reservation->setGuestCounts([1 => 2]);
        $reservation->setStartDate(new \DateTime($arrival));
        $reservation->setEndDate(new \DateTime($departure));

        return $reservation;
    }
}
