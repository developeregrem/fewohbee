<?php

declare(strict_types=1);

namespace App\Tests\Unit\GuestCheckIn;

use App\Entity\Appartment;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Repository\PriceRepository;
use App\Service\GuestCheckIn\GuestCheckInExtrasService;
use App\Service\PriceService;
use PHPUnit\Framework\TestCase;

final class GuestCheckInExtrasServiceTest extends TestCase
{
    public function testWholeStayPriceIsSnapshottedAndCannotChangeBeforeTakeover(): void
    {
        $price = (new Price())->setIsFlatPrice(false)->setIsPerRoom(false)->setIsBookableOnline(true);
        $price->setId(17);
        $price->setDescription('Breakfast');
        $price->setPrice('8.50');
        $reservation = $this->reservation();
        $repository = $this->createStub(PriceRepository::class);
        $repository->method('findBookableOnlineExtras')->willReturn([$price]);
        $pricing = $this->createStub(PriceService::class);
        $pricing->method('getPricesForReservationDays')->willReturn([1 => [$price], 2 => [$price]]);
        $service = new GuestCheckInExtrasService($repository, $pricing);

        $snapshot = $service->snapshot($reservation, [17]);

        self::assertSame([['id' => 17, 'description' => 'Breakfast', 'total' => '34.00']], $snapshot);
        self::assertSame([$price], $service->pricesToApply($reservation, $snapshot));

        $price->setPrice('9.00');
        $this->expectException(\InvalidArgumentException::class);
        $service->pricesToApply($reservation, $snapshot);
    }

    public function testAlreadyBookedServiceIsNeverAddedAgain(): void
    {
        $price = (new Price())->setIsFlatPrice(true)->setIsBookableOnline(true);
        $price->setId(18);
        $price->setDescription('Parking');
        $price->setPrice('10.00');
        $reservation = $this->reservation();
        $reservation->addPrice($price);
        $repository = $this->createStub(PriceRepository::class);
        $repository->method('findBookableOnlineExtras')->willReturn([$price]);
        $pricing = $this->createStub(PriceService::class);
        $pricing->method('getPricesForReservationDays')->willReturn([]);
        $service = new GuestCheckInExtrasService($repository, $pricing);

        self::assertSame([], $service->pricesToApply($reservation, [['id' => 18, 'total' => '10.00']]));
        $this->expectException(\InvalidArgumentException::class);
        $service->snapshot($reservation, [18]);
    }

    private function reservation(): Reservation
    {
        $reservation = new Reservation();
        $reservation->setReservationOrigin(new ReservationOrigin());
        $reservation->setAppartment(new Appartment());
        $reservation->setStartDate(new \DateTime('2026-10-01'));
        $reservation->setEndDate(new \DateTime('2026-10-03'));
        $reservation->setPersons(2);

        return $reservation;
    }
}
