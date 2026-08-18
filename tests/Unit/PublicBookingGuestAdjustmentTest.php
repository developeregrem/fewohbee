<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dto\PublicBooking\RoomTotal;
use App\Entity\Appartment;
use App\Entity\Enum\ModifierType;
use App\Entity\GuestCategory;
use App\Entity\GuestCategoryModifier;
use App\Entity\InvoicePosition;
use App\Entity\Price;
use App\Entity\RoomCategory;
use App\Repository\GuestCategoryModifierRepository;
use App\Repository\GuestCategoryRepository;
use App\Repository\PriceRepository;
use App\Service\InvoiceService;
use App\Service\OnlineBookingConfigService;
use App\Service\PriceService;
use App\Service\PublicPricingService;
use PHPUnit\Framework\TestCase;

/**
 * The guest must never see a price change without an explanation.
 *
 * Step 2 quotes list prices, step 3 shows the per-guest adjustment as its own line.
 * These tests pin both halves: that the adjustment is announced where the amount is
 * not yet knowable, and that it is reported separately once it is.
 */
final class PublicBookingGuestAdjustmentTest extends TestCase
{
    private const PER_HEAD_RATE = 30.0;

    /** A child on a reduced flat rate is announced as a reduction. */
    public function testFlatRateBelowRoomRateIsAnnouncedAsReduction(): void
    {
        $service = $this->makeService(
            [$this->guestCategory(1, 'Erwachsener', true), $this->guestCategory(2, 'Kind 6–17', true)],
            [2 => $this->modifier(ModifierType::FLAT_RATE, 14.0)],
        );

        $adjustment = $service->describeGuestPriceAdjustment(
            $this->room(),
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-04'),
            [1 => 3, 2 => 1],
        );

        self::assertNotNull($adjustment);
        self::assertSame(PublicPricingService::ADJUSTMENT_REDUCTION, $adjustment['direction']);
        self::assertSame(['Kind 6–17'], $adjustment['labels']);
    }

    /** The very same modifier is a surcharge once it exceeds the room's per-head rate. */
    public function testFlatRateAboveRoomRateIsAnnouncedAsSurcharge(): void
    {
        $service = $this->makeService(
            [$this->guestCategory(2, 'Hund', true)],
            [2 => $this->modifier(ModifierType::FLAT_RATE, 45.0)],
        );

        $adjustment = $service->describeGuestPriceAdjustment(
            $this->room(),
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-04'),
            [2 => 1],
        );

        self::assertNotNull($adjustment);
        self::assertSame(PublicPricingService::ADJUSTMENT_SURCHARGE, $adjustment['direction']);
    }

    /**
     * With full-fare seats reserved, whether the reduction survives depends on who
     * shares the room — so the wizard must not promise a direction.
     */
    public function testMinFullPayersLeavesTheDirectionOpen(): void
    {
        $service = $this->makeService(
            [$this->guestCategory(2, 'Kind 6–17', true)],
            [2 => $this->modifier(ModifierType::FLAT_RATE, 14.0)],
        );

        $adjustment = $service->describeGuestPriceAdjustment(
            $this->room(minFullPayers: 2),
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-04'),
            [2 => 1],
        );

        self::assertNotNull($adjustment);
        self::assertSame(PublicPricingService::ADJUSTMENT_MIXED, $adjustment['direction']);
    }

    /** A room sold at a flat or per-room rate is never touched by per-guest modifiers. */
    public function testRoomPricedPerRoomGetsNoAnnouncement(): void
    {
        $service = $this->makeService(
            [$this->guestCategory(2, 'Kind 6–17', true)],
            [2 => $this->modifier(ModifierType::FLAT_RATE, 14.0)],
            perRoom: true,
        );

        self::assertNull($service->describeGuestPriceAdjustment(
            $this->room(),
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-04'),
            [2 => 1],
        ));
    }

    /** An infant in a cot does not occupy a bed and is never priced per head. */
    public function testNonOccupancyGuestTriggersNoAnnouncement(): void
    {
        $service = $this->makeService(
            [$this->guestCategory(3, 'Kleinkind', false)],
            [3 => $this->modifier(ModifierType::FREE, 0.0)],
        );

        self::assertNull($service->describeGuestPriceAdjustment(
            $this->room(),
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-04'),
            [3 => 1],
        ));
    }

    /** A party without any modifier-bearing category gets no announcement at all. */
    public function testPartyWithoutModifiersGetsNoAnnouncement(): void
    {
        $service = $this->makeService([$this->guestCategory(1, 'Erwachsener', true)], []);

        self::assertNull($service->describeGuestPriceAdjustment(
            $this->room(),
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-04'),
            [1 => 2],
        ));
    }

    /**
     * The room total keeps the list price and reports the delta separately — that
     * split is what lets the summary explain the reduction instead of hiding it.
     */
    public function testRoomTotalKeepsListPriceAndReportsAdjustmentSeparately(): void
    {
        $invoiceService = $this->createStub(InvoiceService::class);
        $invoiceService->method('buildAppartmentPositions')->willReturn(['room-position']);
        $invoiceService->method('buildApartmentModifierPositions')->willReturn([$this->modifierPosition()]);
        $invoiceService->method('calculateSums')->willReturnCallback(
            static function ($apps, $poss, array &$vats, float &$brutto, float &$netto, float &$roomTotal, float &$miscTotal): void {
                $vats = [];
                $brutto = $netto = 0.0;
                // 3 nights × 3 guests × 30 € list price, minus 3 × 16 € for the child.
                $roomTotal = 270.0;
                $miscTotal = -48.0;
            }
        );

        $service = $this->makeService([], [], invoiceService: $invoiceService);
        $total = $service->calculateReservationRoomTotal($this->createStub(\App\Entity\Reservation::class));

        self::assertSame(270.0, $total->room, 'the room line must stay at the advertised price');
        self::assertSame(-48.0, $total->modifiers);
        self::assertSame(222.0, $total->total(), 'what the guest pays is unchanged');
        self::assertCount(1, $total->modifierPositions);
    }

    /**
     * @param GuestCategory[]                    $categories
     * @param array<int, GuestCategoryModifier>  $modifiersByCategoryId
     */
    private function makeService(
        array $categories,
        array $modifiersByCategoryId,
        bool $perRoom = false,
        ?InvoiceService $invoiceService = null,
    ): PublicPricingService {
        $categoryRepository = $this->createStub(GuestCategoryRepository::class);
        $categoryRepository->method('findActiveOrdered')->willReturn($categories);

        $modifierRepository = $this->createStub(GuestCategoryModifierRepository::class);
        $modifierRepository->method('findApplicable')->willReturnCallback(
            static fn (GuestCategory $category): ?GuestCategoryModifier => $modifiersByCategoryId[(int) $category->getId()] ?? null
        );

        $price = $this->createStub(Price::class);
        $price->method('getIsFlatPrice')->willReturn(false);
        $price->method('getIsPerRoom')->willReturn($perRoom);
        $price->method('getPrice')->willReturn((string) self::PER_HEAD_RATE);

        $priceService = $this->createStub(PriceService::class);
        $priceService->method('getPricesForReservationDays')->willReturn([[$price]]);

        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getReservationOrigin')->willReturn(null);

        return new PublicPricingService(
            $invoiceService ?? $this->createStub(InvoiceService::class),
            $configService,
            $priceService,
            $this->createStub(PriceRepository::class),
            $categoryRepository,
            $modifierRepository,
        );
    }

    private function guestCategory(int $id, string $name, bool $countedInOccupancy): GuestCategory
    {
        $category = $this->createStub(GuestCategory::class);
        $category->method('getId')->willReturn($id);
        $category->method('getName')->willReturn($name);
        $category->method('isCountedInOccupancy')->willReturn($countedInOccupancy);

        return $category;
    }

    private function modifier(ModifierType $type, float $value): GuestCategoryModifier
    {
        $modifier = $this->createStub(GuestCategoryModifier::class);
        $modifier->method('getType')->willReturn($type);
        $modifier->method('getValueAsFloat')->willReturn($value);

        return $modifier;
    }

    private function room(int $minFullPayers = 0): Appartment
    {
        $category = $this->createStub(RoomCategory::class);
        $category->method('getMinFullPayers')->willReturn($minFullPayers);

        $room = $this->createStub(Appartment::class);
        $room->method('getRoomCategory')->willReturn($category);

        return $room;
    }

    private function modifierPosition(): InvoicePosition
    {
        $position = $this->createStub(InvoicePosition::class);
        $position->method('getDescription')->willReturn('Kind 6–17 — Pauschalpreis 14,00 (3 Nächte × 1 Person)');
        $position->method('getAmount')->willReturn(3);
        $position->method('getPrice')->willReturn('-16.00');

        return $position;
    }
}
