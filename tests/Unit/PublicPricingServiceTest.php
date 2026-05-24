<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\InvoiceAppartment;
use App\Entity\Price;
use App\Entity\RoomCategory;
use App\Repository\PriceRepository;
use App\Service\InvoiceService;
use App\Service\OnlineBookingConfigService;
use App\Service\PriceService;
use App\Service\PublicPricingService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class PublicPricingServiceTest extends TestCase
{
    private function createService(InvoiceService $invoiceService, OnlineBookingConfigService $configService): PublicPricingService
    {
        $priceService = $this->createStub(PriceService::class);
        $priceRepo = $this->createStub(PriceRepository::class);

        return new PublicPricingService($invoiceService, $configService, $priceService, $priceRepo);
    }

    /** Only occupancy levels with non-zero prices should be returned. */
    public function testGetOccupancyPricesReturnsOnlyPricedOptions(): void
    {
        $category = new RoomCategory();
        $room = new Appartment();
        $room->setBedsMax(3);
        $room->setRoomCategory($category);

        $dateFrom = new \DateTimeImmutable('2026-06-01');
        $dateTo = new \DateTimeImmutable('2026-06-03');

        // Simulate: price exists for 1 and 3 persons, but not 2
        $invoiceService = $this->createStub(InvoiceService::class);
        $callCount = 0;
        $invoiceService->method('buildAppartmentPositions')
            ->willReturnCallback(function () use (&$callCount): array {
                ++$callCount;
                // Call 1: 1 person → has position
                // Call 2: 2 persons → no positions (empty)
                // Call 3: 3 persons → has position
                if (2 === $callCount) {
                    return [];
                }

                $position = $this->createStub(InvoiceAppartment::class);
                $position->method('getIsFlatPrice')->willReturn(false);
                $position->method('getAmount')->willReturn(2);
                $position->method('getPrice')->willReturn(1 === $callCount ? 50.0 : 80.0);
                $position->method('getVat')->willReturn(7.0);
                $position->method('getIncludesVat')->willReturn(true);

                return [$position];
            });

        $invoiceService->method('calculateSums')
            ->willReturnCallback(function (
                $apps,
                $poss,
                array &$vats,
                float &$brutto,
                float &$netto,
                float &$appartmentTotal,
                float &$miscTotal
            ): void {
                $appartmentTotal = 0.0;
                foreach ($apps as $app) {
                    $price = $app->getIsFlatPrice() ? $app->getPrice() : $app->getAmount() * $app->getPrice();
                    $appartmentTotal += $price;
                }
            });

        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getReservationOrigin')->willReturn(null);

        $service = $this->createService($invoiceService, $configService);
        $options = $service->getOccupancyPrices($category, $room, $dateFrom, $dateTo, 3);

        // Should only contain 1 and 3 persons (2 persons had no positions)
        self::assertCount(2, $options);
        self::assertArrayHasKey(1, $options);
        self::assertArrayNotHasKey(2, $options);
        self::assertArrayHasKey(3, $options);

        self::assertSame(1, $options[1]['persons']);
        self::assertSame(100.0, $options[1]['totalPrice']); // 2 * 50
        self::assertSame('100,00', $options[1]['totalPriceFormatted']);

        self::assertSame(3, $options[3]['persons']);
        self::assertSame(160.0, $options[3]['totalPrice']); // 2 * 80
        self::assertSame('160,00', $options[3]['totalPriceFormatted']);
    }

    /**
     * When the wizard supplied a guestCounts mix that totals N persons, the
     * matching occupancy option N should reflect the modifier delta (children
     * discount etc.); other options stay at the adult-only baseline so the
     * table still shows "what would N adults cost in this room".
     */
    public function testGetOccupancyPricesAppliesModifierOnlyForMatchingOption(): void
    {
        $category = new RoomCategory();
        $room = new Appartment();
        $room->setBedsMax(3);
        $room->setRoomCategory($category);

        $dateFrom = new \DateTimeImmutable('2026-06-01');
        $dateTo = new \DateTimeImmutable('2026-06-03');

        $invoiceService = $this->createStub(InvoiceService::class);
        $invoiceService->method('buildAppartmentPositions')
            ->willReturnCallback(function (\App\Entity\Reservation $r): array {
                $position = $this->createStub(InvoiceAppartment::class);
                $position->method('getIsFlatPrice')->willReturn(false);
                $position->method('getAmount')->willReturn(2 * $r->getPersons()); // 2 nights × persons
                $position->method('getPrice')->willReturn(50.0);
                $position->method('getVat')->willReturn(7.0);
                $position->method('getIncludesVat')->willReturn(true);

                return [$position];
            });

        // Modifier returns one negative delta line (2 nights × 1 child × −25),
        // but only when the reservation actually carries the matching guestCounts mix.
        $invoiceService->method('buildApartmentModifierPositions')
            ->willReturnCallback(function (array $reservations): array {
                $r = $reservations[0];
                if ([] === $r->getGuestCounts()) {
                    return [];
                }
                $pos = $this->createStub(\App\Entity\InvoicePosition::class);
                $pos->method('getIsFlatPrice')->willReturn(false);
                $pos->method('getAmount')->willReturn(2);
                $pos->method('getPrice')->willReturn(-25.0);
                $pos->method('getVat')->willReturn(7.0);
                $pos->method('getIncludesVat')->willReturn(true);

                return [$pos];
            });

        $invoiceService->method('calculateSums')
            ->willReturnCallback(function (
                $apps,
                $poss,
                array &$vats,
                float &$brutto,
                float &$netto,
                float &$appartmentTotal,
                float &$miscTotal
            ): void {
                $appartmentTotal = 0.0;
                foreach ($apps as $app) {
                    $appartmentTotal += $app->getIsFlatPrice() ? $app->getPrice() : $app->getAmount() * $app->getPrice();
                }
                $miscTotal = 0.0;
                foreach ($poss as $p) {
                    $miscTotal += $p->getIsFlatPrice() ? $p->getPrice() : $p->getAmount() * $p->getPrice();
                }
            });

        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getReservationOrigin')->willReturn(null);

        // User picked 2 adults + 1 child = 3 persons total (all occupancy-counted).
        $guestCounts = [1 => 2, 2 => 1];
        $mixOccupancyPersons = 3;

        $service = $this->createService($invoiceService, $configService);
        $options = $service->getOccupancyPrices($category, $room, $dateFrom, $dateTo, 3, $guestCounts, $mixOccupancyPersons);

        // Option 1 (no mix match) → adult-only: 2 nights × 1 person × 50 = 100
        self::assertSame(100.0, $options[1]['totalPrice']);
        // Option 2 (no mix match) → adult-only: 2 × 2 × 50 = 200
        self::assertSame(200.0, $options[2]['totalPrice']);
        // Option 3 (matches mix) → 2 × 3 × 50 = 300, minus modifier 2 × −25 = −50 → 250
        self::assertSame(250.0, $options[3]['totalPrice']);
    }

    /** When no occupancy level has a price, an empty array is returned. */
    public function testGetOccupancyPricesReturnsEmptyWhenNoPrices(): void
    {
        $category = new RoomCategory();
        $room = new Appartment();
        $room->setBedsMax(2);
        $room->setRoomCategory($category);

        $invoiceService = $this->createStub(InvoiceService::class);
        $invoiceService->method('buildAppartmentPositions')->willReturn([]);

        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getReservationOrigin')->willReturn(null);

        $service = $this->createService($invoiceService, $configService);
        $options = $service->getOccupancyPrices(
            $category,
            $room,
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
            2
        );

        self::assertSame([], $options);
    }
}
