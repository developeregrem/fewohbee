<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Enum\GuestStatisticalGroup;
use App\Entity\GuestCategory;
use App\Entity\Reservation;
use App\Entity\Subsidiary;
use App\Entity\TouristTax;
use App\Entity\TouristTaxRate;
use App\Repository\GuestCategoryRepository;
use App\Repository\TouristTaxRepository;
use App\Service\TouristTaxService;
use PHPUnit\Framework\TestCase;

final class TouristTaxServiceTest extends TestCase
{
    public function testWaivedReturnsEmpty(): void
    {
        $r = $this->makeReservation([1 => 2]);
        $r->setKurtaxeWaived(true);

        $service = $this->makeService([], []);
        self::assertSame([], $service->calculateForReservation($r));
    }

    public function testEmptyTaxesReturnEmpty(): void
    {
        $service = $this->makeService([], []);
        self::assertSame([], $service->calculateForReservation($this->makeReservation([1 => 2])));
    }

    public function testBreakdownPerCategoryAcrossNights(): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);
        $child = $this->makeCategory(2, GuestStatisticalGroup::CHILD);

        $tax = $this->makeTax('Kurtaxe', false, [
            [$adult, '3.00', null],
            [$child, '1.50', 'children'],
        ]);

        $r = $this->makeReservation([1 => 2, 2 => 1]); // 2 nights
        $service = $this->makeService([$tax], [$adult, $child]);

        $rows = $service->calculateForReservation($r);
        self::assertCount(2, $rows);

        $adultRow = $rows[0];
        self::assertSame(2, $adultRow->count);
        self::assertSame(2, $adultRow->nights);
        self::assertSame(3.0, $adultRow->pricePerNight);
        self::assertSame(12.0, $adultRow->total());

        $childRow = $rows[1];
        self::assertSame(3.0, $childRow->total());
        self::assertSame('children', $childRow->reportGroup);
    }

    public function testAppliesOnlyToAdultFiltersChildren(): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);
        $child = $this->makeCategory(2, GuestStatisticalGroup::CHILD);

        // Both rates set, but the tax is adult-only — children must be skipped.
        $tax = $this->makeTax('Beherbergungsabgabe', true, [
            [$adult, '1.00', null],
            [$child, '0.50', null],
        ]);

        $r = $this->makeReservation([1 => 2, 2 => 3]);
        $service = $this->makeService([$tax], [$adult, $child]);

        $rows = $service->calculateForReservation($r);
        self::assertCount(1, $rows);
        self::assertSame((int) $adult->getId(), $rows[0]->categoryId);
        self::assertSame(4.0, $rows[0]->total()); // 2 nights × 2 adults × 1.00
    }

    public function testMultipleActiveTaxesYieldSeparateRows(): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);
        $child = $this->makeCategory(2, GuestStatisticalGroup::CHILD);

        $kurtaxe = $this->makeTax('Kurtaxe', false, [
            [$adult, '3.00', null],
            [$child, '1.50', null],
        ]);
        $beherbergung = $this->makeTax('Beherbergungsabgabe', true, [
            [$adult, '1.00', null],
        ]);

        $r = $this->makeReservation([1 => 1, 2 => 1]);
        $service = $this->makeService([$kurtaxe, $beherbergung], [$adult, $child]);

        $rows = $service->calculateForReservation($r);
        // Kurtaxe: 2 rows (adult + child); Beherbergung: 1 row (adult only)
        self::assertCount(3, $rows);
    }

    public function testTaxExpiringMidStayCoversOnlyValidNights(): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);

        // Tax valid through 2026-05-08. Reservation 2026-05-07 → 2026-05-09
        // = 2 nights (night of 7th covered, night of 8th covered, night of
        // 9th does not exist as overnight). ValidTo=08.05 inclusive ⇒ both
        // overnights covered ⇒ 2× nights.
        $tax = $this->makeTax('Kurtaxe', false, [[$adult, '3.00', null]]);
        $tax->setValidTo(new \DateTime('2026-05-08'));

        $service = $this->makeService([$tax], [$adult]);
        $r = $this->makeReservationWithDates([1 => 1], '2026-05-07', '2026-05-09');
        $rows = $service->calculateForReservation($r);

        self::assertCount(1, $rows);
        self::assertSame(2, $rows[0]->nights);
    }

    public function testTaxStartingMidStayCoversOnlyValidNights(): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);

        // Tax valid from 2026-05-08. Reservation 2026-05-07 → 2026-05-09
        // = night of 7th NOT covered, night of 8th covered ⇒ 1 night.
        $tax = $this->makeTax('Kurtaxe', false, [[$adult, '3.00', null]]);
        $tax->setValidFrom(new \DateTime('2026-05-08'));

        $service = $this->makeService([$tax], [$adult]);
        $r = $this->makeReservationWithDates([1 => 1], '2026-05-07', '2026-05-09');
        $rows = $service->calculateForReservation($r);

        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]->nights);
    }

    public function testTaxNotValidForAnyNightProducesNoRow(): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);

        // Tax valid range is entirely BEFORE the stay.
        $tax = $this->makeTax('Kurtaxe', false, [[$adult, '3.00', null]]);
        $tax->setValidTo(new \DateTime('2026-05-06'));

        $service = $this->makeService([$tax], [$adult]);
        $r = $this->makeReservationWithDates([1 => 1], '2026-05-07', '2026-05-09');
        $rows = $service->calculateForReservation($r);

        self::assertCount(0, $rows);
    }

    public function testZeroCountCategoryIsSkipped(): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);
        $child = $this->makeCategory(2, GuestStatisticalGroup::CHILD);

        $tax = $this->makeTax('Kurtaxe', false, [
            [$adult, '3.00', null],
            [$child, '1.50', null],
        ]);

        $r = $this->makeReservation([1 => 2]); // no children
        $service = $this->makeService([$tax], [$adult, $child]);

        $rows = $service->calculateForReservation($r);
        self::assertCount(1, $rows);
        self::assertSame((int) $adult->getId(), $rows[0]->categoryId);
    }

    /**
     * @param TouristTax[]    $taxes
     * @param GuestCategory[] $categories
     */
    private function makeService(array $taxes, array $categories): TouristTaxService
    {
        $taxRepo = $this->createStub(TouristTaxRepository::class);
        $taxRepo->method('findActiveForSubsidiaryInRange')->willReturn($taxes);
        $taxRepo->method('hasActiveForSubsidiary')->willReturn(!empty($taxes));

        $catRepo = $this->createStub(GuestCategoryRepository::class);
        $catRepo->method('findAll')->willReturn($categories);

        return new TouristTaxService($taxRepo, $catRepo);
    }

    private function makeReservation(array $guestCounts): Reservation
    {
        return $this->makeReservationWithDates($guestCounts, '2026-06-01', '2026-06-03');
    }

    private function makeReservationWithDates(array $guestCounts, string $start, string $end): Reservation
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

    private function makeCategory(int $id, GuestStatisticalGroup $group): GuestCategory
    {
        $c = new GuestCategory();
        $c->setName('cat'.$id);
        $c->setAcronym('C'.$id);
        $c->setStatisticalGroup($group);
        (new \ReflectionProperty(GuestCategory::class, 'id'))->setValue($c, $id);

        return $c;
    }

    /**
     * @param array<int, array{0: GuestCategory, 1: string, 2: ?string}> $rateRows
     */
    private function makeTax(string $name, bool $adultOnly, array $rateRows): TouristTax
    {
        $tax = new TouristTax();
        $tax->setName($name);
        $tax->setAppliesOnlyToAdult($adultOnly);
        (new \ReflectionProperty(TouristTax::class, 'id'))->setValue($tax, crc32($name));

        foreach ($rateRows as [$cat, $price, $group]) {
            $rate = new TouristTaxRate();
            $rate->setGuestCategory($cat);
            $rate->setPricePerNight($price);
            $rate->setReportGroup($group);
            $tax->addRate($rate);
        }

        return $tax;
    }
}
