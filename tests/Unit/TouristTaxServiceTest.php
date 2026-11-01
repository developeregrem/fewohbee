<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Enum\GuestStatisticalGroup;
use App\Entity\Enum\RoomCategoryKind;
use App\Entity\GuestCategory;
use App\Entity\Reservation;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Entity\TouristTax;
use App\Entity\TouristTaxRate;
use App\Repository\GuestCategoryRepository;
use App\Repository\TouristTaxRepository;
use App\Service\TouristTaxService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TouristTaxServiceTest extends TestCase
{
    #[DataProvider('incompleteStayDates')]
    public function testIncompleteDatesCannotSilentlyProduceAnEmptyTaxCalculation(bool $withStart, bool $withEnd): void
    {
        $reservation = new Reservation();
        if ($withStart) {
            $reservation->setStartDate(new \DateTime('2026-06-01'));
        }
        if ($withEnd) {
            $reservation->setEndDate(new \DateTime('2026-06-03'));
        }
        $taxes = $this->createMock(TouristTaxRepository::class);
        $taxes->expects(self::never())->method('findActiveForSubsidiaryInRange');
        $service = new TouristTaxService($taxes, $this->createStub(GuestCategoryRepository::class));

        $this->expectException(\TypeError::class);

        $service->calculateForReservation($reservation);
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function incompleteStayDates(): iterable
    {
        yield 'both dates missing' => [false, false];
        yield 'start missing' => [false, true];
        yield 'end missing' => [true, false];
    }

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

    public function testEventSpaceIsChargedNoTouristTax(): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);
        $tax = $this->makeTax('Kurtaxe', false, [[$adult, '3.00', null]]);
        $r = $this->makeReservation([1 => 2]);
        $r->getAppartment()->setRoomCategory((new RoomCategory())->setKind(RoomCategoryKind::EVENT_SPACE));

        self::assertSame([], $this->makeService([$tax], [$adult])->calculateForReservation($r));
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

    public function testRangeFilterIsTimezoneAgnostic(): void
    {
        // Regression: reservation start is created in the app's local timezone (e.g. Europe/Berlin),
        // but report range comes from filter parsing in UTC. A naive timestamp-based compare
        // dropped the first night of the month due to the 2h offset.
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);
        $tax = $this->makeTax('Kurtaxe', false, [[$adult, '2.00', null]]);
        $service = $this->makeService([$tax], [$adult]);

        $r = new Reservation();
        $r->setStartDate(new \DateTime('2026-06-01 00:00:00', new \DateTimeZone('Europe/Berlin')));
        $r->setEndDate(new \DateTime('2026-06-04 00:00:00', new \DateTimeZone('Europe/Berlin')));
        $apt = new Appartment();
        $apt->setObject(new Subsidiary());
        $r->setAppartment($apt);
        $r->setGuestCounts([1 => 1]);

        $rangeStart = new \DateTime('2026-06-01 00:00:00', new \DateTimeZone('UTC'));
        $rangeEnd = new \DateTime('2026-06-30 23:59:59', new \DateTimeZone('UTC'));
        $rows = $service->calculateForReservation($r, $rangeStart, $rangeEnd);

        self::assertCount(1, $rows);
        self::assertSame(3, $rows[0]->nights, 'All 3 nights must be counted despite TZ mismatch between reservation and range');
    }

    public function testRangeFilterCountsFullStayWhenInsideRange(): void
    {
        // Reproduces the report scenario: reservation 01.06-04.06 (3 nights) reported
        // for full June. Range filter must NOT drop any of the 3 nights.
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);
        $tax = $this->makeTax('Kurtaxe', false, [[$adult, '2.00', null]]);

        $service = $this->makeService([$tax], [$adult]);
        $r = $this->makeReservationWithDates([1 => 1], '2026-06-01', '2026-06-04');

        $rangeStart = new \DateTime('2026-06-01 00:00:00');
        $rangeEnd = new \DateTime('2026-06-30 23:59:59');
        $rows = $service->calculateForReservation($r, $rangeStart, $rangeEnd);

        self::assertCount(1, $rows);
        self::assertSame(3, $rows[0]->nights);
        self::assertSame(6.0, $rows[0]->total());
    }

    #[DataProvider('calendarBoundaries')]
    public function testCalendarBoundariesKeepNightCountsAndReservationDates(string $arrival, string $departure, int $expectedNights): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);
        $tax = $this->makeTax('Kurtaxe', false, [[$adult, '3.00', null]]);
        $service = $this->makeService([$tax], [$adult]);
        $start = new \DateTime($arrival, new \DateTimeZone('Europe/Berlin'));
        $end = new \DateTime($departure, new \DateTimeZone('Europe/Berlin'));
        $originalStart = $start->format('Y-m-d H:i:s.u e P');
        $originalEnd = $end->format('Y-m-d H:i:s.u e P');
        $reservation = $this->makeReservation([1 => 2]);
        $reservation->setStartDate($start);
        $reservation->setEndDate($end);

        $rows = $service->calculateForReservation($reservation);

        self::assertCount(1, $rows);
        self::assertSame($expectedNights, $rows[0]->nights);
        self::assertSame(6.0 * $expectedNights, $rows[0]->total());
        self::assertEquals($rows, $service->calculateForReservation($reservation));
        self::assertSame($start, $reservation->getStartDate());
        self::assertSame($end, $reservation->getEndDate());
        self::assertSame($originalStart, $start->format('Y-m-d H:i:s.u e P'));
        self::assertSame($originalEnd, $end->format('Y-m-d H:i:s.u e P'));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function calendarBoundaries(): iterable
    {
        yield 'start of daylight saving time' => ['2026-03-28', '2026-03-31', 3];
        yield 'end of daylight saving time' => ['2026-10-24', '2026-10-27', 3];
        yield 'leap day and month boundary' => ['2028-02-28', '2028-03-01', 2];
        yield 'year boundary' => ['2026-12-31', '2027-01-02', 2];
        yield 'day use with a time component' => ['2026-06-01 15:30:00.123456', '2026-06-01 15:30:00.123456', 1];
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

    /** @param array<int, int> $guestCounts */
    private function makeReservation(array $guestCounts): Reservation
    {
        return $this->makeReservationWithDates($guestCounts, '2026-06-01', '2026-06-03');
    }

    /** @param array<int, int> $guestCounts */
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
