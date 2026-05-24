<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Enum\GuestStatisticalGroup;
use App\Entity\Enum\PercentageBase;
use App\Entity\Enum\TaxCalculationMode;
use App\Entity\GuestCategory;
use App\Entity\Reservation;
use App\Entity\Subsidiary;
use App\Entity\TouristTax;
use App\Repository\GuestCategoryRepository;
use App\Repository\TouristTaxRepository;
use App\Service\PriceService;
use App\Service\TouristTaxService;
use PHPUnit\Framework\TestCase;

final class TouristTaxServicePercentTest extends TestCase
{
    public function testPercentPerRoomFlatNightlyPrice(): void
    {
        // 3 nights × 100 € apartment × 6 % = 18 €
        $tax = $this->makeTax('Beherbergungssteuer Dresden', TaxCalculationMode::PERCENT_PER_ROOM, '6.00', PercentageBase::NET);
        $service = $this->makeService(
            [$tax],
            ['2026-06-01' => 100.0, '2026-06-02' => 100.0, '2026-06-03' => 100.0],
        );

        $r = $this->makeReservation([1 => 2], '2026-06-01', '2026-06-04');
        $rows = $service->calculateForReservation($r);

        self::assertCount(1, $rows);
        self::assertSame(TaxCalculationMode::PERCENT_PER_ROOM, $rows[0]->calculationMode);
        self::assertSame(3, $rows[0]->nights);
        self::assertSame(1, $rows[0]->count);
        self::assertSame(18.0, $rows[0]->total());
        self::assertSame(300.0, $rows[0]->apartmentBase);
        self::assertSame(6.0, $rows[0]->percentageRate);
    }

    public function testPercentPerRoomVariableNightlyPrices(): void
    {
        // Tag 1 = 80 €, Tag 2 = 120 €, 5 % → 4 + 6 = 10 €
        $tax = $this->makeTax('City Tax Köln', TaxCalculationMode::PERCENT_PER_ROOM, '5.00', PercentageBase::NET);
        $service = $this->makeService(
            [$tax],
            ['2026-06-01' => 80.0, '2026-06-02' => 120.0],
        );

        $r = $this->makeReservation([1 => 1], '2026-06-01', '2026-06-03');
        $rows = $service->calculateForReservation($r);

        self::assertCount(1, $rows);
        self::assertSame(10.0, $rows[0]->total());
        self::assertSame(200.0, $rows[0]->apartmentBase);
    }

    public function testPercentModeWithoutRateOrBaseSkips(): void
    {
        $tax = $this->makeTax('Broken', TaxCalculationMode::PERCENT_PER_ROOM, null, null);
        $service = $this->makeService([$tax], ['2026-06-01' => 100.0]);

        $r = $this->makeReservation([1 => 1], '2026-06-01', '2026-06-02');
        self::assertSame([], $service->calculateForReservation($r));
    }

    public function testFlatModeStillProducesIdenticalLegacyResult(): void
    {
        // Regression: ein PER_NIGHT_FLAT-Tax verhält sich exakt wie früher.
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);
        $tax = new TouristTax();
        $tax->setName('Kurtaxe Klassisch');
        $tax->setCalculationMode(TaxCalculationMode::PER_NIGHT_FLAT);
        (new \ReflectionProperty(TouristTax::class, 'id'))->setValue($tax, 99);
        $rate = new \App\Entity\TouristTaxRate();
        $rate->setGuestCategory($adult);
        $rate->setPricePerNight('3.00');
        $tax->addRate($rate);

        $service = $this->makeService([$tax], []);
        $r = $this->makeReservation([1 => 2], '2026-06-01', '2026-06-03');
        $rows = $service->calculateForReservation($r);

        self::assertCount(1, $rows);
        self::assertSame(12.0, $rows[0]->total()); // 2 nights × 2 adults × 3
        self::assertSame(TaxCalculationMode::PER_NIGHT_FLAT, $rows[0]->calculationMode);
    }

    /**
     * @param TouristTax[]        $taxes
     * @param array<string,float> $apartmentTotals
     */
    private function makeService(array $taxes, array $apartmentTotals): TouristTaxService
    {
        $taxRepo = $this->createStub(TouristTaxRepository::class);
        $taxRepo->method('findActiveForSubsidiaryInRange')->willReturn($taxes);

        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);
        $child = $this->makeCategory(2, GuestStatisticalGroup::CHILD);
        $catRepo = $this->createStub(GuestCategoryRepository::class);
        $catRepo->method('findAll')->willReturn([$adult, $child]);

        $priceService = $this->createStub(PriceService::class);
        $priceService->method('getApartmentTotalsPerNight')->willReturn($apartmentTotals);

        return new TouristTaxService($taxRepo, $catRepo, $priceService);
    }

    private function makeCategory(int $id, GuestStatisticalGroup $group): GuestCategory
    {
        $c = new GuestCategory();
        $c->setName('cat'.$id);
        $c->setAcronym('C'.$id);
        $c->setStatisticalGroup($group);
        (new \ReflectionProperty(GuestCategory::class, 'id'))->setValue($c, $id);

        return $c;
    }

    private function makeTax(string $name, TaxCalculationMode $mode, ?string $percent, ?PercentageBase $base, bool $adultOnly = false): TouristTax
    {
        $tax = new TouristTax();
        $tax->setName($name);
        $tax->setCalculationMode($mode);
        $tax->setPercentageRate($percent);
        $tax->setPercentageBase($base);
        $tax->setAppliesOnlyToAdult($adultOnly);
        (new \ReflectionProperty(TouristTax::class, 'id'))->setValue($tax, crc32($name));

        return $tax;
    }

    private function makeReservation(array $guestCounts, string $start, string $end): Reservation
    {
        $r = new Reservation();
        $r->setStartDate(new \DateTime($start));
        $r->setEndDate(new \DateTime($end));
        $apt = new Appartment();
        $apt->setObject(new Subsidiary());
        $r->setAppartment($apt);
        $r->setGuestCounts($guestCounts);

        return $r;
    }
}
