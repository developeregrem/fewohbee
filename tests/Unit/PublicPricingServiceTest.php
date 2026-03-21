<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\InvoiceAppartment;
use App\Entity\RoomCategory;
use App\Service\InvoiceService;
use App\Service\OnlineBookingConfigService;
use App\Service\PublicPricingService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class PublicPricingServiceTest extends TestCase
{
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

        $service = new PublicPricingService($invoiceService, $configService);
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

        $service = new PublicPricingService($invoiceService, $configService);
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
