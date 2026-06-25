<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\RoomCategory;
use App\Repository\PriceRepository;
use App\Service\InvoiceService;
use App\Service\OnlineBookingConfigService;
use App\Service\PriceService;
use App\Service\PublicPricingService;
use PHPUnit\Framework\TestCase;

final class PublicPricingServiceExtrasTest extends TestCase
{
    private ReservationOrigin $origin;
    private \DateTimeImmutable $dateFrom;
    private \DateTimeImmutable $dateTo;
    private RoomCategory $single;
    private RoomCategory $double;
    private Appartment $singleRoom;
    private Appartment $doubleRoom;

    protected function setUp(): void
    {
        $this->origin = new ReservationOrigin();
        $this->dateFrom = new \DateTimeImmutable('2026-06-01');
        $this->dateTo = new \DateTimeImmutable('2026-06-08'); // 7 nights

        $this->single = $this->makeCategory(1, 'Einzelzimmer');
        $this->double = $this->makeCategory(2, 'Doppelzimmer');

        $this->singleRoom = new Appartment();
        $this->singleRoom->setBedsMax(1);
        $this->singleRoom->setRoomCategory($this->single);

        $this->doubleRoom = new Appartment();
        $this->doubleRoom->setBedsMax(2);
        $this->doubleRoom->setRoomCategory($this->double);
    }

    private function makeCategory(int $id, string $name): RoomCategory
    {
        $category = new RoomCategory();
        $category->setName($name);
        $ref = new \ReflectionProperty($category, 'id');
        $ref->setValue($category, $id);

        return $category;
    }

    private function createPrice(int $id, string $description, float $unitPrice, bool $isFlatPrice = false, bool $isPerRoom = false, ?RoomCategory $category = null, bool $mandatory = false): Price
    {
        $price = new Price();
        $price->setId($id);
        $price->setDescription($description);
        $price->setPrice((string) $unitPrice);
        $price->setIsFlatPrice($isFlatPrice);
        $price->setIsPerRoom($isPerRoom);
        $price->setIsBookableOnline(true);
        $price->setIsMandatoryOnline($mandatory);
        $price->setRoomCategory($category);
        $price->setType(1); // misc price

        return $price;
    }

    /**
     * Build the service. The PriceRepository stub mirrors the real category filter:
     * findBookableOnlineExtras returns extras that are global (no category) or whose
     * category matches the reservation's apartment category.
     *
     * @param Price[] $allExtras
     */
    private function buildService(array $allExtras, int $validDays = 7): PublicPricingService
    {
        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getReservationOrigin')->willReturn($this->origin);

        $priceRepo = $this->createStub(PriceRepository::class);
        $priceRepo->method('findBookableOnlineExtras')->willReturnCallback(
            function (Reservation $reservation) use ($allExtras): array {
                $category = $reservation->getAppartment()?->getRoomCategory();

                return array_values(array_filter($allExtras, static function (Price $p) use ($category): bool {
                    $pc = $p->getRoomCategory();

                    return null === $pc || ($category !== null && $pc->getId() === $category->getId());
                }));
            }
        );

        $priceService = $this->createStub(PriceService::class);
        $priceService->method('getPricesForReservationDays')
            ->willReturnCallback(function () use ($validDays): array {
                $result = [0 => null];
                for ($i = 1; $i <= 7; ++$i) {
                    $result[$i] = $i <= $validDays ? 'valid' : null;
                }

                return $result;
            });

        $invoiceService = $this->createStub(InvoiceService::class);

        return new PublicPricingService($invoiceService, $configService, $priceService, $priceRepo);
    }

    /** @return array<int, array{categoryId: ?int, categoryName: ?string, sampleRoom: Appartment}> */
    private function samplesForBothCategories(): array
    {
        return [
            ['categoryId' => 1, 'categoryName' => 'Einzelzimmer', 'sampleRoom' => $this->singleRoom],
            ['categoryId' => 2, 'categoryName' => 'Doppelzimmer', 'sampleRoom' => $this->doubleRoom],
        ];
    }

    // ───────────────────────── catalogExtras ─────────────────────────

    public function testCatalogReturnsGlobalExtraOncePerBooking(): void
    {
        $breakfast = $this->createPrice(10, 'Breakfast', 14.00); // global, per_person
        $service = $this->buildService([$breakfast], 7);

        $extras = $service->catalogExtras($this->samplesForBothCategories(), $this->dateFrom, $this->dateTo, 3, 3);

        self::assertCount(1, $extras);
        self::assertSame(10, $extras[0]['id']);
        self::assertNull($extras[0]['categoryId']);
        self::assertFalse($extras[0]['autoQuantity']);
        self::assertSame('per_person_night', $extras[0]['calculationType']);
        // global per_person uses totalPersons: 14 × 3 × 7 = 294
        self::assertSame(294.0, $extras[0]['pricePerUnit']);
    }

    public function testCatalogListsCategoryBoundExtrasPerCategory(): void
    {
        $global = $this->createPrice(10, 'Breakfast', 14.00);
        $ezCleaning = $this->createPrice(20, 'Endreinigung', 60.00, true, false, $this->single);
        $dzCleaning = $this->createPrice(30, 'Endreinigung', 50.00, true, false, $this->double);

        $service = $this->buildService([$global, $ezCleaning, $dzCleaning], 7);
        $extras = $service->catalogExtras($this->samplesForBothCategories(), $this->dateFrom, $this->dateTo, 3, 3);

        self::assertCount(3, $extras);
        // Global first, then category-bound.
        self::assertNull($extras[0]['categoryId']);

        $byId = [];
        foreach ($extras as $e) {
            $byId[$e['id']] = $e;
        }
        self::assertSame(1, $byId[20]['categoryId']);
        self::assertTrue($byId[20]['autoQuantity']);
        self::assertSame('Einzelzimmer', $byId[20]['categoryName']);
        self::assertSame(2, $byId[30]['categoryId']);
        self::assertSame('Doppelzimmer', $byId[30]['categoryName']);
    }

    // ───────────────────────── resolveExtras ─────────────────────────

    /** @return array<int, array{categoryId: ?int, categoryName: ?string, sampleRoom: Appartment, roomCount: int, persons: int}> */
    private function mixedBuckets(): array
    {
        // 1 single room (1 guest) + 2 double rooms (4 guests total)
        return [
            ['categoryId' => 1, 'categoryName' => 'Einzelzimmer', 'sampleRoom' => $this->singleRoom, 'roomCount' => 1, 'persons' => 1],
            ['categoryId' => 2, 'categoryName' => 'Doppelzimmer', 'sampleRoom' => $this->doubleRoom, 'roomCount' => 2, 'persons' => 4],
        ];
    }

    public function testResolveCategoryBoundFlatQuantityMatchesRoomCount(): void
    {
        // The user's scenario: mandatory flat cleaning, 60€ for single, 50€ for double.
        $ezCleaning = $this->createPrice(20, 'Endreinigung', 60.00, true, false, $this->single, true);
        $dzCleaning = $this->createPrice(30, 'Endreinigung', 50.00, true, false, $this->double, true);

        $service = $this->buildService([$ezCleaning, $dzCleaning], 7);
        $resolved = $service->resolveExtras($this->mixedBuckets(), $this->dateFrom, $this->dateTo, 5, 3, []);

        $byId = [];
        foreach ($resolved as $e) {
            $byId[$e['id']] = $e;
        }
        // Single: 1 room → 1×60 = 60
        self::assertSame(1, $byId[20]['quantity']);
        self::assertSame(60.0, $byId[20]['lineTotal']);
        // Double: 2 rooms → 2×50 = 100
        self::assertSame(2, $byId[30]['quantity']);
        self::assertSame(100.0, $byId[30]['lineTotal']);
        self::assertTrue($byId[30]['autoQuantity']);
    }

    public function testResolveCategoryBoundPerRoomMultipliesByNights(): void
    {
        $dzParking = $this->createPrice(30, 'Parkplatz', 5.00, false, true, $this->double, true);
        $service = $this->buildService([$dzParking], 7);

        $resolved = $service->resolveExtras($this->mixedBuckets(), $this->dateFrom, $this->dateTo, 5, 3, []);

        self::assertCount(1, $resolved);
        // per_room: 5 × 7 nights × 2 rooms = 70
        self::assertSame('per_room_night', $resolved[0]['calculationType']);
        self::assertSame(2, $resolved[0]['quantity']);
        self::assertSame(70.0, $resolved[0]['lineTotal']);
    }

    public function testResolveCategoryBoundPerPersonUsesCategoryPersons(): void
    {
        $dzBreakfast = $this->createPrice(30, 'Frühstück', 10.00, false, false, $this->double, true);
        $service = $this->buildService([$dzBreakfast], 7);

        $resolved = $service->resolveExtras($this->mixedBuckets(), $this->dateFrom, $this->dateTo, 5, 3, []);

        self::assertCount(1, $resolved);
        // per_person: 10 × 4 (double-room persons) × 7 nights = 280, quantity 1
        self::assertSame('per_person_night', $resolved[0]['calculationType']);
        self::assertSame(1, $resolved[0]['quantity']);
        self::assertSame(280.0, $resolved[0]['lineTotal']);
    }

    public function testResolveOptionalCategoryBoundRequiresSelection(): void
    {
        $dzCleaning = $this->createPrice(30, 'Endreinigung', 50.00, true, false, $this->double, false);
        $service = $this->buildService([$dzCleaning], 7);

        // Not selected → excluded
        $resolved = $service->resolveExtras($this->mixedBuckets(), $this->dateFrom, $this->dateTo, 5, 3, []);
        self::assertSame([], $resolved);

        // Selected (on/off flag) → included with derived quantity (2 double rooms)
        $resolved = $service->resolveExtras($this->mixedBuckets(), $this->dateFrom, $this->dateTo, 5, 3, [30 => 1]);
        self::assertCount(1, $resolved);
        self::assertSame(2, $resolved[0]['quantity']);
        self::assertSame(100.0, $resolved[0]['lineTotal']);
    }

    public function testResolveGlobalFlatKeepsGuestSelectableQuantity(): void
    {
        $cleaning = $this->createPrice(10, 'Cleaning', 45.00, true, false); // global flat
        $service = $this->buildService([$cleaning], 7);

        // Guest selects 2 → 2×45 = 90, clamped to totalRooms (3)
        $resolved = $service->resolveExtras($this->mixedBuckets(), $this->dateFrom, $this->dateTo, 5, 3, [10 => 2]);
        self::assertCount(1, $resolved);
        self::assertFalse($resolved[0]['autoQuantity']);
        self::assertSame(2, $resolved[0]['quantity']);
        self::assertSame(90.0, $resolved[0]['lineTotal']);
    }

    public function testResolveCategoryBoundOnlyAppliesWhenCategoryBooked(): void
    {
        // Mandatory cleaning bound to single, but only double rooms booked.
        $ezCleaning = $this->createPrice(20, 'Endreinigung', 60.00, true, false, $this->single, true);
        $service = $this->buildService([$ezCleaning], 7);

        $doubleOnly = [
            ['categoryId' => 2, 'categoryName' => 'Doppelzimmer', 'sampleRoom' => $this->doubleRoom, 'roomCount' => 2, 'persons' => 4],
        ];
        $resolved = $service->resolveExtras($doubleOnly, $this->dateFrom, $this->dateTo, 4, 2, []);

        self::assertSame([], $resolved);
    }

    public function testResolveReturnsEmptyWhenNoOriginConfigured(): void
    {
        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getReservationOrigin')->willReturn(null);
        $service = new PublicPricingService(
            $this->createStub(InvoiceService::class),
            $configService,
            $this->createStub(PriceService::class),
            $this->createStub(PriceRepository::class),
        );

        self::assertSame([], $service->resolveExtras($this->mixedBuckets(), $this->dateFrom, $this->dateTo, 5, 3, []));
        self::assertSame([], $service->catalogExtras($this->samplesForBothCategories(), $this->dateFrom, $this->dateTo, 5, 3));
    }

    public function testResolveSkipsPerPersonExtraWithNoValidDays(): void
    {
        $dzBreakfast = $this->createPrice(30, 'Frühstück', 10.00, false, false, $this->double, true);
        $service = $this->buildService([$dzBreakfast], 0); // no valid days

        $resolved = $service->resolveExtras($this->mixedBuckets(), $this->dateFrom, $this->dateTo, 5, 3, []);
        self::assertSame([], $resolved);
    }
}
