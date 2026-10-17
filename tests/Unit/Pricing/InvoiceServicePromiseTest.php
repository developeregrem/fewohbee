<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Dto\Pricing\NightRate;
use App\Dto\Pricing\PricePromise;
use App\Dto\Pricing\PromisedExtra;
use App\Dto\Pricing\PromisedNight;
use App\Entity\Appartment;
use App\Entity\AppSettings;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Service\AppSettingsService;
use App\Service\InvoiceService;
use App\Service\InvoiceSumCalculator;
use App\Service\OriginFeeCalculator;
use App\Service\PriceService;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Invoice positions built for a booking with a price promise: the promised amounts are billed,
 * while VAT rate, description and revenue account still come from the price row.
 */
final class InvoiceServicePromiseTest extends TestCase
{
    public function testAPositionEndsWhereThePromisedPriceEnds(): void
    {
        $row = $this->price(17, '95.00', 2);
        $reservation = $this->reservation('2026-12-20', '2026-12-23');
        $promised = new PromisedNight(17, '80.00', false, false, true);
        $priceService = $this->createStub(PriceService::class);
        $priceService->method('getNightRates')->willReturn([
            NightRate::promised(new \DateTimeImmutable('2026-12-20'), $row, $promised),
            NightRate::promised(new \DateTimeImmutable('2026-12-21'), $row, $promised),
            NightRate::live(new \DateTimeImmutable('2026-12-22'), $row),
        ]);

        $positions = $this->service($priceService)->buildAppartmentPositions($reservation);

        self::assertCount(2, $positions);
        self::assertSame('80.00', $positions[0]->getPrice());
        self::assertSame('2026-12-22', $positions[0]->getEndDate()->format('Y-m-d'));
        self::assertSame('95.00', $positions[1]->getPrice());
        self::assertSame(7.0, (float) $positions[1]->getVat());
        self::assertSame(2 * 2 * 80.0 + 2 * 95.0, $positions[0]->getTotalPriceRaw() + $positions[1]->getTotalPriceRaw());
    }

    public function testABookedExtraIsBilledAtItsPromisedPriceEvenWhenSwitchedOff(): void
    {
        $breakfast = $this->price(5, '14.00', 1);
        $breakfast->setActive(false);
        $dog = $this->price(6, '10.00', 1);
        $reservation = $this->reservation('2026-12-20', '2026-12-22');
        $reservation->addPrice($breakfast);
        $reservation->addPrice($dog);
        $promise = new PricePromise('2026-10-01', 'ctx', [], [5 => new PromisedExtra(5, '12.50', true)]);

        $priceService = $this->createStub(PriceService::class);
        $priceService->method('promiseOf')->willReturn($promise);
        $priceService->method('getPricesForReservationDays')->willReturnCallback(
            static fn (Reservation $r, int $type, ?Collection $prices): array => [0 => null, 1 => $prices?->getValues(), 2 => $prices?->getValues()]
        );

        $positions = $this->service($priceService)->buildMiscPositions([$reservation], true);

        self::assertCount(2, $positions);
        self::assertSame('12.50', $positions[0]->getPrice());
        self::assertSame(4, $positions[0]->getAmount());
        self::assertSame('10.00', $positions[1]->getPrice(), 'an extra without promise is billed from its row');
    }

    public function testTheSameExtraBookedAtDifferentPricesIsNotMerged(): void
    {
        $breakfast = $this->price(5, '14.00', 1);
        $early = $this->reservation('2026-12-20', '2026-12-21');
        $early->addPrice($breakfast);
        $late = $this->reservation('2026-12-20', '2026-12-21');
        $late->addPrice($breakfast);
        $promises = new \SplObjectStorage();
        $promises[$early] = new PricePromise('2026-09-01', 'ctx', [], [5 => new PromisedExtra(5, '12.50', true)]);
        $promises[$late] = null;

        $priceService = $this->createStub(PriceService::class);
        $priceService->method('promiseOf')->willReturnCallback(static fn (Reservation $r): ?PricePromise => $promises[$r]);
        $priceService->method('getPricesForReservationDays')->willReturnCallback(
            static fn (Reservation $r, int $type, ?Collection $prices): array => [0 => null, 1 => $prices?->getValues()]
        );

        $positions = $this->service($priceService)->buildMiscPositions([$early, $late], true);

        self::assertSame(['12.50', '14.00'], array_map(static fn ($p): string => (string) $p->getPrice(), $positions));
    }

    private function service(PriceService $priceService): InvoiceService
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $appSettingsService = $this->createStub(AppSettingsService::class);
        $appSettingsService->method('getSettings')->willReturn(new AppSettings());

        return new InvoiceService(
            $this->createStub(EntityManagerInterface::class),
            $priceService,
            $translator,
            $appSettingsService,
            new InvoiceSumCalculator(),
            new OriginFeeCalculator(new InvoiceSumCalculator()),
        );
    }

    private function price(int $id, string $amount, int $type): Price
    {
        $price = new Price();
        $price->setId($id);
        $price->setType($type);
        $price->setDescription('row '.$id);
        $price->setPrice($amount);
        $price->setVat(7.0);
        $price->setIncludesVat(true);
        $price->setIsFlatPrice(false);
        $price->setIsPerRoom(false);
        $price->setActive(true);

        return $price;
    }

    private function reservation(string $arrival, string $departure): Reservation
    {
        $apartment = new Appartment();
        $apartment->setNumber('1');
        $apartment->setDescription('Room');
        $apartment->setBedsMax(2);

        $reservation = new Reservation();
        $reservation->setAppartment($apartment);
        $reservation->setPersons(2);
        $reservation->setStartDate(new \DateTime($arrival));
        $reservation->setEndDate(new \DateTime($departure));

        return $reservation;
    }
}
