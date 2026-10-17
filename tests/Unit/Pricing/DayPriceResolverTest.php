<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Entity\Appartment;
use App\Entity\AppSettings;
use App\Entity\DayPrice;
use App\Entity\Enum\DayPriceSource;
use App\Entity\Price;
use App\Entity\ReservationOrigin;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Repository\DayPriceRepository;
use App\Repository\PriceRepository;
use App\Service\AppSettingsService;
use App\Service\OnlineBooking\OnlineBookingConfigService;
use App\Service\Pricing\DayPriceResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class DayPriceResolverTest extends TestCase
{
    public function testADayPriceIsComparedWithTheListPriceForItsGuests(): void
    {
        // 150 € for two guests against 60 € per head = 25 % above the price list.
        $adjustments = $this->resolver([$this->dayPrice('2026-12-31', 150.0, 2)], [[$this->row(60.0)]])->adjustments($this->room(), $this->day('2026-12-30'), $this->day('2027-01-02'));

        self::assertSame(['2026-12-31'], array_keys($adjustments));
        self::assertEqualsWithDelta(25.0, $adjustments['2026-12-31']->percent, 0.0001);
        self::assertFalse($adjustments['2026-12-31']->limited);
    }

    public function testARoomPricedPerRoomIsComparedAsAWhole(): void
    {
        $perRoom = $this->row(100.0);
        $perRoom->setIsPerRoom(true);

        $adjustments = $this->resolver([$this->dayPrice('2026-12-31', 150.0, 2)], [[$perRoom]])->adjustments($this->room(), $this->day('2026-12-31'), $this->day('2027-01-01'));

        self::assertEqualsWithDelta(50.0, $adjustments['2026-12-31']->percent, 0.0001);
    }

    public function testOnlyDayPricesFromAssistantsAndProgramsAreHeldWithinTheLimits(): void
    {
        $manual = $this->dayPrice('2026-12-30', 240.0, 2);
        $delivered = $this->dayPrice('2026-12-31', 240.0, 2, DayPriceSource::API);

        $adjustments = $this->resolver([$manual, $delivered], [[$this->row(60.0)]])->adjustments($this->room(), $this->day('2026-12-30'), $this->day('2027-01-01'));

        self::assertEqualsWithDelta(100.0, $adjustments['2026-12-30']->percent, 0.0001);
        self::assertSame(50.0, $adjustments['2026-12-31']->percent);
        self::assertTrue($adjustments['2026-12-31']->limited);
    }

    public function testAFlatPriceGivesNothingToCompareWith(): void
    {
        $flat = $this->row(300.0);
        $flat->setIsFlatPrice(true);

        self::assertSame([], $this->resolver([$this->dayPrice('2026-12-31', 150.0, 2)], [[$flat]])->adjustments($this->room(), $this->day('2026-12-31'), $this->day('2027-01-01')));
    }

    public function testACategoryWithoutSingleNightsUsesItsShortestMinimumStay(): void
    {
        $week = $this->row(50.0);
        $week->setMinStay(7);
        $threeNights = $this->row(75.0);
        $threeNights->setMinStay(3);

        // No row for one night; the second query returns the longer stays, longest first.
        $adjustments = $this->resolver([$this->dayPrice('2026-12-31', 180.0, 2)], [[], [$week, $threeNights]])->adjustments($this->room(), $this->day('2026-12-31'), $this->day('2027-01-01'));

        self::assertEqualsWithDelta(20.0, $adjustments['2026-12-31']->percent, 0.0001);
    }

    /**
     * @param list<DayPrice>    $dayPrices
     * @param list<list<Price>> $rowsPerQuery answers to consecutive findApartmentPrices() calls
     */
    private function resolver(array $dayPrices, array $rowsPerQuery): DayPriceResolver
    {
        $byNight = [];
        foreach ($dayPrices as $dayPrice) {
            $byNight[$dayPrice->getNight()->format('Y-m-d')] = $dayPrice;
        }
        $repository = $this->createStub(DayPriceRepository::class);
        $repository->method('findForWindow')->willReturn($byNight);
        $prices = $this->createStub(PriceRepository::class);
        $prices->method('findApartmentPrices')->willReturnOnConsecutiveCalls(...$rowsPerQuery);
        $onlineBooking = $this->createStub(OnlineBookingConfigService::class);
        $onlineBooking->method('getReservationOrigin')->willReturn(new ReservationOrigin());
        $settings = $this->createStub(AppSettingsService::class);
        $settings->method('getSettings')->willReturn(new AppSettings());

        return new DayPriceResolver($repository, $prices, $onlineBooking, $this->createStub(EntityManagerInterface::class), $settings);
    }

    private function dayPrice(string $night, float $amount, int $persons, DayPriceSource $source = DayPriceSource::MANUAL): DayPrice
    {
        $dayPrice = new DayPrice(new Subsidiary(), new RoomCategory(), $this->day($night));
        $dayPrice->set($amount, $persons, $source, null, new \DateTimeImmutable('2026-10-02'));

        return $dayPrice;
    }

    private function row(float $amount): Price
    {
        $price = new Price();
        $price->setPrice($amount);
        $price->setIsFlatPrice(false);
        $price->setIsPerRoom(false);
        $price->setAllDays(true);
        $price->setAllPeriods(true);
        $price->setMinStay(1);

        return $price;
    }

    private function room(): Appartment
    {
        $room = new Appartment();
        $room->setObject(new Subsidiary());
        $room->setRoomCategory(new RoomCategory());

        return $room;
    }

    private function day(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }
}
