<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Price;
use App\Entity\ReservationOrigin;
use App\Entity\RoomCategory;
use App\Repository\PriceRepository;
use App\Service\InvoiceService;
use App\Service\OnlineBookingConfigService;
use App\Service\PriceService;
use App\Service\PublicPricingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PublicPricingServiceExtrasTest extends TestCase
{
    private Appartment $sampleRoom;
    private ReservationOrigin $origin;
    private \DateTimeImmutable $dateFrom;
    private \DateTimeImmutable $dateTo;

    protected function setUp(): void
    {
        $category = new RoomCategory();
        $this->sampleRoom = new Appartment();
        $this->sampleRoom->setBedsMax(2);
        $this->sampleRoom->setRoomCategory($category);

        $this->origin = new ReservationOrigin();
        $this->dateFrom = new \DateTimeImmutable('2026-06-01');
        $this->dateTo = new \DateTimeImmutable('2026-06-08'); // 7 nights
    }

    private function createPrice(int $id, string $description, float $unitPrice, bool $isFlatPrice = false, bool $isPerRoom = false): Price
    {
        $price = new Price();
        $price->setId($id);
        $price->setDescription($description);
        $price->setPrice((string) $unitPrice);
        $price->setIsFlatPrice($isFlatPrice);
        $price->setIsPerRoom($isPerRoom);
        $price->setIsBookableOnline(true);
        $price->setType(1); // misc price

        return $price;
    }

    /**
     * Build the service with stubs for PriceRepository and PriceService.
     *
     * @param Price[] $repoExtras  Extras returned by findBookableOnlineExtras
     * @param int     $validDays   Number of valid days returned by getPricesForReservationDays (for each extra)
     */
    private function buildService(array $repoExtras, int $validDays = 7): PublicPricingService
    {
        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getReservationOrigin')->willReturn($this->origin);

        $priceRepo = $this->createStub(PriceRepository::class);
        $priceRepo->method('findBookableOnlineExtras')->willReturn($repoExtras);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($priceRepo);

        // Simulate getPricesForReservationDays: return price for days 1..$validDays, null beyond
        $priceService = $this->createStub(PriceService::class);
        $priceService->method('getPricesForReservationDays')
            ->willReturnCallback(function () use ($validDays): array {
                $result = [];
                $result[0] = null;
                $nights = 7;
                for ($i = 1; $i <= $nights; ++$i) {
                    $result[$i] = $i <= $validDays ? 'valid' : null;
                }

                return $result;
            });

        $invoiceService = $this->createStub(InvoiceService::class);

        return new PublicPricingService($invoiceService, $configService, $priceService, $em);
    }

    public function testPerPersonNightCalculation(): void
    {
        $breakfast = $this->createPrice(10, 'Breakfast', 14.00);
        $service = $this->buildService([$breakfast], 7);

        $extras = $service->getBookableExtras($this->sampleRoom, $this->dateFrom, $this->dateTo, 2, 1);

        self::assertCount(1, $extras);
        self::assertSame(10, $extras[0]['id']);
        self::assertSame('Breakfast', $extras[0]['description']);
        self::assertSame('per_person_night', $extras[0]['calculationType']);
        self::assertSame(14.0, $extras[0]['unitPrice']);
        self::assertSame(1, $extras[0]['maxQuantity']);
        // 14.00 × 2 persons × 7 nights = 196.00 (single unit = all persons)
        self::assertSame(196.0, $extras[0]['pricePerUnit']);
        self::assertSame('196,00', $extras[0]['pricePerUnitFormatted']);
    }

    public function testPerRoomNightReturnsPerUnitPrice(): void
    {
        $parking = $this->createPrice(20, 'Parking', 8.00, false, true);
        $service = $this->buildService([$parking], 7);

        $extras = $service->getBookableExtras($this->sampleRoom, $this->dateFrom, $this->dateTo, 2, 3);

        self::assertCount(1, $extras);
        self::assertSame('per_room_night', $extras[0]['calculationType']);
        self::assertSame(3, $extras[0]['maxQuantity']);
        // pricePerUnit = 8.00 × 7 nights = 56.00 (for 1 room)
        self::assertSame(56.0, $extras[0]['pricePerUnit']);
        self::assertSame('56,00', $extras[0]['pricePerUnitFormatted']);
    }

    public function testFlatPriceReturnsPerUnitPrice(): void
    {
        $cleaning = $this->createPrice(30, 'Final cleaning', 45.00, true, false);
        $service = $this->buildService([$cleaning], 7);

        $extras = $service->getBookableExtras($this->sampleRoom, $this->dateFrom, $this->dateTo, 2, 2);

        self::assertCount(1, $extras);
        self::assertSame('flat', $extras[0]['calculationType']);
        self::assertSame(2, $extras[0]['maxQuantity']);
        // pricePerUnit = 45.00 (flat, per 1 reservation)
        self::assertSame(45.0, $extras[0]['pricePerUnit']);
        self::assertSame('45,00', $extras[0]['pricePerUnitFormatted']);
    }

    public function testExtraWithNoValidDaysIsSkipped(): void
    {
        $seasonal = $this->createPrice(40, 'Beach chair', 5.00);
        $service = $this->buildService([$seasonal], 0);

        $extras = $service->getBookableExtras($this->sampleRoom, $this->dateFrom, $this->dateTo, 2, 1);

        self::assertSame([], $extras);
    }

    public function testFlatPriceWithNoValidDaysIsStillShown(): void
    {
        $cleaning = $this->createPrice(50, 'Setup fee', 25.00, true, false);
        $service = $this->buildService([$cleaning], 0);

        $extras = $service->getBookableExtras($this->sampleRoom, $this->dateFrom, $this->dateTo, 1, 1);

        self::assertCount(1, $extras);
        self::assertSame('flat', $extras[0]['calculationType']);
        self::assertSame(25.0, $extras[0]['pricePerUnit']);
        self::assertSame(1, $extras[0]['maxQuantity']);
    }

    public function testReturnsEmptyWhenNoOriginConfigured(): void
    {
        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getReservationOrigin')->willReturn(null);

        $priceRepo = $this->createStub(PriceRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($priceRepo);
        $priceService = $this->createStub(PriceService::class);
        $invoiceService = $this->createStub(InvoiceService::class);

        $service = new PublicPricingService($invoiceService, $configService, $priceService, $em);

        $extras = $service->getBookableExtras($this->sampleRoom, $this->dateFrom, $this->dateTo, 2, 1);

        self::assertSame([], $extras);
    }

    public function testReturnsEmptyWhenNoBookableExtrasExist(): void
    {
        $service = $this->buildService([], 7);

        $extras = $service->getBookableExtras($this->sampleRoom, $this->dateFrom, $this->dateTo, 2, 1);

        self::assertSame([], $extras);
    }

    public function testMultipleExtrasWithMixedTypes(): void
    {
        $breakfast = $this->createPrice(10, 'Breakfast', 14.00);
        $parking = $this->createPrice(20, 'Parking', 8.00, false, true);
        $cleaning = $this->createPrice(30, 'Cleaning', 45.00, true, false);

        $service = $this->buildService([$breakfast, $parking, $cleaning], 7);

        $extras = $service->getBookableExtras($this->sampleRoom, $this->dateFrom, $this->dateTo, 2, 2);

        self::assertCount(3, $extras);

        // Breakfast: per_person_night → pricePerUnit = 14 × 2 persons × 7 nights = 196, maxQty = 1
        self::assertSame(196.0, $extras[0]['pricePerUnit']);
        self::assertSame(1, $extras[0]['maxQuantity']);
        // Parking: per_room_night → pricePerUnit = 8 × 7 nights = 56, maxQty = 2
        self::assertSame(56.0, $extras[1]['pricePerUnit']);
        self::assertSame(2, $extras[1]['maxQuantity']);
        // Cleaning: flat → pricePerUnit = 45, maxQty = 2
        self::assertSame(45.0, $extras[2]['pricePerUnit']);
        self::assertSame(2, $extras[2]['maxQuantity']);
    }

    public function testPartialValidDaysReducesTotal(): void
    {
        $breakfast = $this->createPrice(10, 'Breakfast', 10.00);
        $service = $this->buildService([$breakfast], 3);

        $extras = $service->getBookableExtras($this->sampleRoom, $this->dateFrom, $this->dateTo, 2, 1);

        self::assertCount(1, $extras);
        // 10.00 × 2 persons × 3 valid days = 60.00
        self::assertSame(60.0, $extras[0]['pricePerUnit']);
    }

    public function testSingleRoomExtraHasMaxQuantityOne(): void
    {
        $dog = $this->createPrice(60, 'Dog', 5.00, false, true);
        $service = $this->buildService([$dog], 7);

        // Only 1 room → maxQuantity should be 1 (no quantity selector needed)
        $extras = $service->getBookableExtras($this->sampleRoom, $this->dateFrom, $this->dateTo, 2, 1);

        self::assertCount(1, $extras);
        self::assertSame(1, $extras[0]['maxQuantity']);
        // 5 × 7 nights = 35 per unit
        self::assertSame(35.0, $extras[0]['pricePerUnit']);
    }
}
