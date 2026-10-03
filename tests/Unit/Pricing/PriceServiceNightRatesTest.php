<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Dto\Pricing\GuestAdjustment;
use App\Dto\Pricing\PricePromise;
use App\Dto\Pricing\PromisedLine;
use App\Dto\Pricing\PromisedNight;
use App\Entity\Appartment;
use App\Entity\Enum\GuestStatisticalGroup;
use App\Entity\Enum\ModifierType;
use App\Entity\Enum\PercentageBase;
use App\Entity\GuestCategory;
use App\Entity\GuestCategoryModifier;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Repository\GuestCategoryModifierRepository;
use App\Repository\GuestCategoryRepository;
use App\Repository\PriceRepository;
use App\Service\PriceService;
use App\Service\ReservationPeriodService;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PriceServiceNightRatesTest extends TestCase
{
    public function testPromisedNightsKeepTheirPriceAndOthersFollowThePriceList(): void
    {
        $row = $this->price(17, '95.00');
        $reservation = $this->reservation();
        $this->promise($reservation, ['2026-12-20' => new PromisedNight(17, '80.00', false, false, true)]);

        $rates = $this->service($row)->getNightRates($reservation);

        self::assertSame('80.00', $rates[0]?->unit);
        self::assertTrue($rates[0]->isPromised());
        self::assertSame('95.00', $rates[1]?->unit);
        self::assertFalse($rates[1]->isPromised());
    }

    public function testAPromiseMadeForOtherGuestsIsIgnored(): void
    {
        $row = $this->price(17, '95.00');
        $reservation = $this->reservation();
        $this->promise($reservation, ['2026-12-20' => new PromisedNight(17, '80.00', false, false, true)]);
        $reservation->setGuestCounts([1 => 3]);

        $rates = $this->service($row)->getNightRates($reservation);

        self::assertSame('95.00', $rates[0]?->unit);
    }

    public function testIgnoringThePromiseReadsThePriceList(): void
    {
        $row = $this->price(17, '95.00');
        $reservation = $this->reservation();
        $this->promise($reservation, ['2026-12-20' => new PromisedNight(17, '80.00', false, false, true)]);

        self::assertSame('95.00', $this->service($row)->getNightRates($reservation, ignorePromise: true)[0]?->unit);
    }

    public function testAPromisedNightKeepsItsGuestLinesWhenTheModifierChanged(): void
    {
        $row = $this->price(17, '50.00');
        $reservation = $this->reservation();
        $reservation->setGuestCounts([1 => 1, 2 => 1]);
        $this->promise($reservation, [
            '2026-12-20' => new PromisedNight(17, '40.00', false, false, true, [
                new PromisedLine(1, 1, '40.00'),
                new PromisedLine(2, 1, '20.00', new GuestAdjustment(ModifierType::DISCOUNT_PERCENT, 50.0)),
            ]),
        ]);
        // Today the child pays 70 % less; the booking was made at 50 %.
        $modifier = new GuestCategoryModifier();
        $modifier->setCategory($this->category(2, GuestStatisticalGroup::CHILD));
        $modifier->setType(ModifierType::DISCOUNT_PERCENT);
        $modifier->setValue('70');

        $breakdowns = $this->service($row, [$modifier])->getPriceBreakdownForReservation($reservation);

        self::assertSame(20.0, $breakdowns[0]->lines[1]->unitPrice);
        self::assertSame(50.0, $breakdowns[0]->lines[1]->adjustment?->value);
        self::assertEqualsWithDelta(15.0, $breakdowns[1]->lines[1]->unitPrice, 0.001, 'the second night is not promised');
    }

    public function testTheCityTaxBaseUsesThePromisedAmount(): void
    {
        $row = $this->price(17, '95.00');
        $row->setNumberOfPersons(2);
        $reservation = $this->reservation();
        $this->promise($reservation, ['2026-12-20' => new PromisedNight(17, '80.00', false, false, true)]);

        $totals = $this->service($row)->getApartmentTotalsPerNight($reservation, PercentageBase::GROSS);

        self::assertSame(160.0, $totals['2026-12-20']);
        self::assertSame(190.0, $totals['2026-12-21']);
    }

    /** @param array<string, PromisedNight> $nights */
    private function promise(Reservation $reservation, array $nights): void
    {
        $promise = new PricePromise('2026-10-01', PricePromise::contextKey($reservation), $nights, []);
        $reservation->setPricePromise($promise->toArray());
    }

    /** @param list<GuestCategoryModifier> $modifiers */
    private function service(Price $row, array $modifiers = []): PriceService
    {
        $prices = $this->createStub(PriceRepository::class);
        $prices->method('findBy')->willReturn([$row]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($prices);

        $categories = $this->createStub(GuestCategoryRepository::class);
        $categories->method('findAll')->willReturn([
            $this->category(1, GuestStatisticalGroup::ADULT),
            $this->category(2, GuestStatisticalGroup::CHILD),
        ]);
        $modifierRepository = $this->createStub(GuestCategoryModifierRepository::class);
        $modifierRepository->method('findActiveOn')->willReturn($modifiers);

        return new class($em, $categories, $modifierRepository, $row) extends PriceService {
            public function __construct(
                EntityManagerInterface $em,
                GuestCategoryRepository $categories,
                GuestCategoryModifierRepository $modifiers,
                private readonly Price $row,
            ) {
                parent::__construct($em, new ReservationPeriodService(), $categories, $modifiers);
            }

            public function getPricesForReservationDays(Reservation $reservation, int $type, ?Collection $prices = null): array
            {
                return [[$this->row], [$this->row], [$this->row]];
            }
        };
    }

    private function price(int $id, string $amount): Price
    {
        $price = new Price();
        $price->setId($id);
        $price->setPrice($amount);
        $price->setVat(7.0);
        $price->setIncludesVat(true);
        $price->setIsFlatPrice(false);
        $price->setIsPerRoom(false);

        return $price;
    }

    private function category(int $id, GuestStatisticalGroup $group): GuestCategory
    {
        $category = new GuestCategory();
        $category->setName('cat'.$id);
        $category->setAcronym('C'.$id);
        $category->setStatisticalGroup($group);
        (new \ReflectionProperty(GuestCategory::class, 'id'))->setValue($category, $id);

        return $category;
    }

    private function reservation(): Reservation
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
        $reservation->setStartDate(new \DateTime('2026-12-20'));
        $reservation->setEndDate(new \DateTime('2026-12-22'));

        return $reservation;
    }
}
