<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dto\PriceBreakdown;
use App\Dto\PriceBreakdownLine;
use App\Entity\AppSettings;
use App\Entity\Enum\GuestStatisticalGroup;
use App\Entity\Enum\ModifierType;
use App\Entity\GuestCategory;
use App\Entity\GuestCategoryModifier;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Service\AppSettingsService;
use App\Service\InvoiceService;
use App\Service\PriceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InvoiceServiceApartmentModifierTest extends TestCase
{
    public function testNoModifierLinesProducesNoPositions(): void
    {
        $base = $this->makePrice('100.00');
        $r = new Reservation();
        $breakdowns = [
            $this->makeBreakdown($base, []),
            $this->makeBreakdown($base, []),
        ];

        $service = $this->createService($r, $breakdowns);
        self::assertSame([], $service->buildApartmentModifierPositions([$r]));
    }

    public function testDiscountPercentEmitsNegativeDeltaPosition(): void
    {
        $base = $this->makePrice('100.00');
        $child = $this->makeCategory(2, GuestStatisticalGroup::CHILD);
        $modifier = $this->makeModifier($child, ModifierType::DISCOUNT_PERCENT, '50');

        // 2 nights, 1 child, child unitPrice = 50 (= base 100 with 50% off)
        $r = new Reservation();
        $breakdowns = [
            $this->makeBreakdown($base, [new PriceBreakdownLine($child, 1, 50.0, $modifier)]),
            $this->makeBreakdown($base, [new PriceBreakdownLine($child, 1, 50.0, $modifier)]),
        ];

        $service = $this->createService($r, $breakdowns);
        $positions = $service->buildApartmentModifierPositions([$r]);

        self::assertCount(1, $positions);
        // delta per unit = 50 - 100 = -50; amount = 2 nights × 1 child = 2
        self::assertSame('-50.00', $positions[0]->getPrice());
        self::assertSame(2, $positions[0]->getAmount());
        self::assertSame(-100.0, $positions[0]->getTotalPriceRaw());
        self::assertSame('apartment_modifier', $positions[0]->getPositionGroup());
    }

    public function testFreeProducesFullNegativeDelta(): void
    {
        $base = $this->makePrice('80.00');
        $infant = $this->makeCategory(3, GuestStatisticalGroup::INFANT);
        $modifier = $this->makeModifier($infant, ModifierType::FREE, '0');

        $r = new Reservation();
        $breakdowns = [
            $this->makeBreakdown($base, [new PriceBreakdownLine($infant, 1, 0.0, $modifier)]),
        ];

        $service = $this->createService($r, $breakdowns);
        $positions = $service->buildApartmentModifierPositions([$r]);

        self::assertCount(1, $positions);
        self::assertSame('-80.00', $positions[0]->getPrice());
        self::assertSame(1, $positions[0]->getAmount());
    }

    public function testSurchargeEmitsPositiveDelta(): void
    {
        $base = $this->makePrice('100.00');
        $other = $this->makeCategory(4, GuestStatisticalGroup::OTHER);
        $modifier = $this->makeModifier($other, ModifierType::SURCHARGE_ABSOLUTE, '15');

        $r = new Reservation();
        $breakdowns = [
            $this->makeBreakdown($base, [new PriceBreakdownLine($other, 1, 115.0, $modifier)]),
        ];

        $service = $this->createService($r, $breakdowns);
        $positions = $service->buildApartmentModifierPositions([$r]);

        self::assertCount(1, $positions);
        self::assertSame('15.00', $positions[0]->getPrice());
    }

    public function testFlatPriceReservationIsSkipped(): void
    {
        $base = $this->makePrice('200.00');
        $base->setIsFlatPrice(true);
        $child = $this->makeCategory(2, GuestStatisticalGroup::CHILD);
        $modifier = $this->makeModifier($child, ModifierType::DISCOUNT_PERCENT, '50');

        $r = new Reservation();
        $breakdowns = [
            $this->makeBreakdown($base, [new PriceBreakdownLine($child, 1, 100.0, $modifier)]),
        ];

        $service = $this->createService($r, $breakdowns);
        self::assertSame([], $service->buildApartmentModifierPositions([$r]));
    }

    public function testPerRoomReservationIsSkipped(): void
    {
        $base = $this->makePrice('200.00');
        $base->setIsPerRoom(true);
        $child = $this->makeCategory(2, GuestStatisticalGroup::CHILD);
        $modifier = $this->makeModifier($child, ModifierType::FREE, '0');

        $r = new Reservation();
        $breakdowns = [
            $this->makeBreakdown($base, [new PriceBreakdownLine($child, 1, 0.0, $modifier)]),
        ];

        $service = $this->createService($r, $breakdowns);
        self::assertSame([], $service->buildApartmentModifierPositions([$r]));
    }

    public function testNonOccupancyCategoryEmitsNoDelta(): void
    {
        // Baby with isCountedInOccupancy=false is excluded from `persons`, so
        // the apartment line never billed it — emitting a "discount" would
        // subtract a charge that was never added.
        $base = $this->makePrice('30.00');
        $infant = $this->makeCategory(3, GuestStatisticalGroup::INFANT, isCountedInOccupancy: false);
        $modifier = $this->makeModifier($infant, ModifierType::FREE, '0');

        $r = new Reservation();
        $breakdowns = [
            $this->makeBreakdown($base, [new PriceBreakdownLine($infant, 1, 0.0, $modifier)]),
        ];

        $service = $this->createService($r, $breakdowns);
        self::assertSame([], $service->buildApartmentModifierPositions([$r]));
    }

    public function testZeroDeltaIsSkipped(): void
    {
        // FLAT_RATE = base ⇒ delta = 0 ⇒ no position emitted (would be noise).
        $base = $this->makePrice('100.00');
        $child = $this->makeCategory(2, GuestStatisticalGroup::CHILD);
        $modifier = $this->makeModifier($child, ModifierType::FLAT_RATE, '100');

        $r = new Reservation();
        $breakdowns = [
            $this->makeBreakdown($base, [new PriceBreakdownLine($child, 1, 100.0, $modifier)]),
        ];

        $service = $this->createService($r, $breakdowns);
        self::assertSame([], $service->buildApartmentModifierPositions([$r]));
    }

    /**
     * @param PriceBreakdown[] $breakdowns
     */
    private function createService(Reservation $r, array $breakdowns): InvoiceService
    {
        $em = $this->createStub(EntityManagerInterface::class);

        $priceService = $this->createMock(PriceService::class);
        $priceService->method('getPriceBreakdownForReservation')->with($r)->willReturn($breakdowns);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            fn (string $id, array $params = []) => $id.':'.implode(',', array_values($params))
        );

        $appSettings = new AppSettings();
        $appSettingsService = $this->createStub(AppSettingsService::class);
        $appSettingsService->method('getSettings')->willReturn($appSettings);

        return new InvoiceService($em, $priceService, $translator, $appSettingsService);
    }

    /**
     * @param PriceBreakdownLine[] $lines
     */
    private function makeBreakdown(?Price $base, array $lines): PriceBreakdown
    {
        $b = new PriceBreakdown(new \DateTime('2026-06-01'), $base);
        foreach ($lines as $line) {
            $b->addLine($line);
        }

        return $b;
    }

    private function makePrice(string $value): Price
    {
        $p = new Price();
        $p->setPrice($value);
        $p->setVat(7.0);
        $p->setDescription('apt');

        return $p;
    }

    private function makeCategory(int $id, GuestStatisticalGroup $group, bool $isCountedInOccupancy = true): GuestCategory
    {
        $c = new GuestCategory();
        $c->setName('cat'.$id);
        $c->setAcronym('C'.$id);
        $c->setStatisticalGroup($group);
        $c->setIsCountedInOccupancy($isCountedInOccupancy);
        (new \ReflectionProperty(GuestCategory::class, 'id'))->setValue($c, $id);

        return $c;
    }

    private function makeModifier(GuestCategory $category, ModifierType $type, string $value): GuestCategoryModifier
    {
        $m = new GuestCategoryModifier();
        $m->setCategory($category);
        $m->setType($type);
        $m->setValue($value);
        (new \ReflectionProperty(GuestCategoryModifier::class, 'id'))->setValue($m, abs(crc32($category->getId().$type->value)) % 100000);

        return $m;
    }
}
