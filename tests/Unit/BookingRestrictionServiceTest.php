<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\BookingRestrictionRule;
use App\Entity\Enum\BookingRestrictionType as Type;
use App\Entity\RoomCategory;
use App\Exception\InvalidReservationPeriodException;
use App\Repository\BookingRestrictionRuleRepository;
use App\Service\BookingRestrictionService;
use App\Service\ReservationPeriodService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for arrival/night semantics, arrival and departure closures,
 * precedence, category scope, the effect table and the exported daily states.
 */
final class BookingRestrictionServiceTest extends TestCase
{
    #[DataProvider('stayCases')]
    public function testArrivalAndOccupiedNightRules(string $arrival, string $departure, Type $type, int $required, bool $allowed): void
    {
        $rules = [$this->rule($type, 4, [1, 2, 3, 4]), $this->rule($type, 2, [5, 6, 7])];
        $result = $this->service()->checkStay(new RoomCategory(), new \DateTimeImmutable($arrival), new \DateTimeImmutable($departure), $rules);
        self::assertSame($required, $result->requiredNights);
        self::assertSame($allowed, $result->isAllowed());
    }

    /**
     * The table from issue #286, checked against both meanings of a minimum stay.
     *
     * @return iterable<string, array{string, string, Type, int, bool}>
     */
    public static function stayCases(): iterable
    {
        yield 'Friday departure is not a night' => ['2026-09-14', '2026-09-18', Type::MIN_STAY_THROUGH, 4, true];
        yield 'Friday to Sunday' => ['2026-09-11', '2026-09-13', Type::MIN_STAY_THROUGH, 2, true];
        yield 'Saturday to Monday' => ['2026-09-12', '2026-09-14', Type::MIN_STAY_THROUGH, 2, true];
        yield 'Sunday to Tuesday (issue 286)' => ['2026-09-13', '2026-09-15', Type::MIN_STAY_THROUGH, 4, false];
        yield 'Saturday to Tuesday' => ['2026-09-12', '2026-09-15', Type::MIN_STAY_THROUGH, 4, false];
        yield 'Saturday to Wednesday' => ['2026-09-12', '2026-09-16', Type::MIN_STAY_THROUGH, 4, true];
        yield 'arrival mode keeps the released Sunday behaviour' => ['2026-09-13', '2026-09-15', Type::MIN_STAY_ARRIVAL, 2, true];
        yield 'arrival mode ignores later weekday nights' => ['2026-09-12', '2026-09-15', Type::MIN_STAY_ARRIVAL, 2, true];
        yield 'Monday arrival' => ['2026-09-14', '2026-09-17', Type::MIN_STAY_ARRIVAL, 4, false];
        yield 'Tuesday arrival' => ['2026-09-15', '2026-09-18', Type::MIN_STAY_ARRIVAL, 4, false];
        yield 'Wednesday arrival' => ['2026-09-16', '2026-09-19', Type::MIN_STAY_ARRIVAL, 4, false];
        yield 'Thursday arrival' => ['2026-09-17', '2026-09-20', Type::MIN_STAY_ARRIVAL, 4, false];
    }

    public function testFridayNightDoesNotChangeThursdayArrivalMinimum(): void
    {
        $result = $this->service()->checkStay(new RoomCategory(), new \DateTimeImmutable('2026-09-17'), new \DateTimeImmutable('2026-09-19'), [
            $this->rule(Type::MIN_STAY_ARRIVAL, 2, [1, 2, 3, 4]), $this->rule(Type::MIN_STAY_ARRIVAL, 6, [5, 6, 7]),
        ]);
        self::assertTrue($result->isAllowed());
        self::assertSame(2, $result->requiredNights);
    }

    public function testSpecialPeriodReplacesUnlimitedRulesEvenWhenLowering(): void
    {
        $unlimited = $this->rule(Type::MIN_STAY_ARRIVAL, 7);
        $special = $this->period(Type::MIN_STAY_ARRIVAL, 1, '2026-09-13', '2026-09-14');
        $result = $this->service()->checkStay(new RoomCategory(), new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-14'), [$unlimited, $special]);
        self::assertTrue($result->isAllowed());
        self::assertSame($special, $result->rule ?? $special);
        self::assertSame(1, $result->requiredNights);
    }

    public function testCategoryAndGlobalOverlappingPeriodsTakeMaximum(): void
    {
        $category = $this->category(1);
        $global = $this->period(Type::MIN_STAY_ARRIVAL, 7, '2026-09-01', '2026-10-01');
        $specific = $this->period(Type::MIN_STAY_ARRIVAL, 3, '2026-09-01', '2026-10-01');
        $specific->setAllCategories(false);
        $specific->setCategories([$category]);
        $result = $this->service()->checkStay($category, new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-16'), [$specific, $global]);
        self::assertSame(7, $result->requiredNights);
        self::assertSame($global, $result->rule);
    }

    public function testSpecialPeriodStartingDuringStayOnlyAffectsNightRules(): void
    {
        $night = $this->period(Type::MIN_STAY_THROUGH, 4, '2026-09-14', '2026-09-15');
        $arrival = $this->period(Type::MIN_STAY_ARRIVAL, 9, '2026-09-14', '2026-09-15');
        $result = $this->service()->checkStay(new RoomCategory(), new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-15'), [$night, $arrival]);
        self::assertSame(4, $result->requiredNights);
        self::assertSame($night, $result->rule);
        self::assertSame('2026-09-14', $result->ruleDate?->format('Y-m-d'));
    }

    public function testDepartureOnPeriodStartIsNotAffected(): void
    {
        $result = $this->service()->checkStay(new RoomCategory(), new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-14'), [
            $this->period(Type::MIN_STAY_THROUGH, 4, '2026-09-14', '2026-09-15'),
        ]);
        self::assertSame(1, $result->requiredNights);
        self::assertTrue($result->isAllowed());
    }

    public function testNightAtExclusivePeriodEndFallsBackToUnlimitedRule(): void
    {
        $result = $this->service()->checkStay(new RoomCategory(), new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-15'), [
            $this->rule(Type::MIN_STAY_THROUGH, 2), $this->period(Type::MIN_STAY_THROUGH, 9, '2026-09-13', '2026-09-14'),
        ]);
        self::assertSame(2, $result->requiredNights);
    }

    public function testLowerNightExceptionDoesNotCancelArrivalMinimum(): void
    {
        $result = $this->service()->checkStay(new RoomCategory(), new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-15'), [
            $this->rule(Type::MIN_STAY_ARRIVAL, 4), $this->period(Type::MIN_STAY_THROUGH, 1, '2026-09-13', '2026-09-15'),
        ]);
        self::assertSame(4, $result->requiredNights);
        self::assertFalse($result->isAllowed());
    }

    public function testClosedToArrivalRejectsAnyStayLength(): void
    {
        // 2026-09-13 is a Sunday.
        $closure = $this->rule(Type::CLOSED_TO_ARRIVAL, null, [7]);
        $service = $this->service();
        foreach (['2026-09-14', '2026-09-20', '2026-10-04'] as $departure) {
            $result = $service->checkStay(new RoomCategory(), new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable($departure), [$closure]);
            self::assertFalse($result->isAllowed());
            self::assertSame(Type::CLOSED_TO_ARRIVAL, $result->reason);
            self::assertSame($closure, $result->rule);
        }
    }

    public function testClosedToArrivalOnlyAffectsTheArrivalDay(): void
    {
        // Sunday closed for arrivals, but a Friday arrival may still occupy the Sunday night.
        $result = $this->service()->checkStay(new RoomCategory(), new \DateTimeImmutable('2026-09-11'), new \DateTimeImmutable('2026-09-15'), [
            $this->rule(Type::CLOSED_TO_ARRIVAL, null, [7]),
        ]);
        self::assertTrue($result->isAllowed());
    }

    public function testClosedToDepartureActsOnTheDepartureDayWhichIsNotAnOccupiedNight(): void
    {
        // Tuesday closed for departures: leaving on Tuesday fails, staying through it does not.
        $closure = $this->rule(Type::CLOSED_TO_DEPARTURE, null, [2]);
        $service = $this->service();

        $leaving = $service->checkStay(new RoomCategory(), new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-15'), [$closure]);
        self::assertFalse($leaving->isAllowed());
        self::assertSame(Type::CLOSED_TO_DEPARTURE, $leaving->reason);
        self::assertSame('2026-09-15', $leaving->ruleDate?->format('Y-m-d'));

        $through = $service->checkStay(new RoomCategory(), new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-16'), [$closure]);
        self::assertTrue($through->isAllowed());
    }

    public function testClosuresAddUpAndAreNotReplacedByASpecialPeriod(): void
    {
        // Unlike a minimum stay, a dated closure never lifts an unlimited one.
        $result = $this->service()->checkStay(new RoomCategory(), new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-15'), [
            $this->rule(Type::CLOSED_TO_ARRIVAL, null, [7]),
            $this->period(Type::CLOSED_TO_ARRIVAL, null, '2026-12-24', '2026-12-27'),
        ]);
        self::assertFalse($result->isAllowed());
        self::assertSame(Type::CLOSED_TO_ARRIVAL, $result->reason);
    }

    public function testCategoryScopeAndDisabledRules(): void
    {
        $category = $this->category(1);
        $otherCategory = $this->category(2);
        $specific = $this->rule(Type::MIN_STAY_ARRIVAL, 4);
        $specific->setAllCategories(false);
        $specific->setCategories([$category]);
        $disabled = $this->rule(Type::MIN_STAY_ARRIVAL, 9);
        $disabled->setEnabled(false);

        $service = $this->service();
        $arrival = new \DateTimeImmutable('2026-09-13');
        $departure = new \DateTimeImmutable('2026-09-15');

        self::assertSame(4, $service->checkStay($category, $arrival, $departure, [$specific, $disabled])->requiredNights);
        self::assertSame(1, $service->checkStay($otherCategory, $arrival, $departure, [$specific])->requiredNights);

        // Losing the last category makes a scoped rule inert rather than global.
        $specific->setCategories([]);
        self::assertSame(1, $service->checkStay($category, $arrival, $departure, [$specific])->requiredNights);
    }

    public function testDatesUseCalendarNightsAcrossDaylightSavingAndYearEnd(): void
    {
        $service = $this->service();
        $zone = new \DateTimeZone('Europe/Berlin');
        foreach ([['2026-03-28', '2026-03-30'], ['2026-10-24', '2026-10-26'], ['2026-12-31', '2027-01-02']] as [$start, $end]) {
            $result = $service->checkStay(new RoomCategory(), new \DateTimeImmutable($start, $zone), new \DateTimeImmutable($end, $zone), []);
            self::assertSame(2, $result->nights);
        }
    }

    public function testDailyExportCarriesEveryChannelFieldExplicitly(): void
    {
        $service = $this->service();
        $category = new RoomCategory();
        $arrival = new \DateTimeImmutable('2026-09-13');
        $departure = new \DateTimeImmutable('2026-09-15');
        $rules = [
            $this->rule(Type::MIN_STAY_ARRIVAL, 2),
            $this->rule(Type::MIN_STAY_THROUGH, 4, [1]),
            $this->rule(Type::CLOSED_TO_DEPARTURE, null, [2]),
        ];

        $days = $service->getDailyRestrictions($category, $arrival, $departure, $rules);
        self::assertSame([
            'date' => '2026-09-13', 'min_stay_arrival' => 2, 'min_stay_through' => 1,
            'closed_to_arrival' => false, 'closed_to_departure' => false,
        ], $days['2026-09-13']->toChannelValues());
        self::assertSame(4, $days['2026-09-14']->toChannelValues()['min_stay_through']);
        self::assertSame(4, $service->checkStay($category, $arrival, $departure, $rules)->requiredNights);

        // Without any rule the export still states the neutral values instead of omitting them.
        self::assertSame([
            'date' => '2026-09-13', 'min_stay_arrival' => 1, 'min_stay_through' => 1,
            'closed_to_arrival' => false, 'closed_to_departure' => false,
        ], $service->getDailyRestrictions($category, $arrival, $departure, [])['2026-09-13']->toChannelValues());
    }

    public function testWeekMatrixShowsTheNonMonotonicEffectAndAgreesWithCheckStay(): void
    {
        $service = $this->service();
        $category = new RoomCategory();
        $rules = [$this->rule(Type::MIN_STAY_THROUGH, 4, [1, 2, 3, 4]), $this->rule(Type::MIN_STAY_THROUGH, 2, [5, 6, 7])];
        // Monday 2026-09-14 starts the week, so the Saturday row is the sixth one.
        $rows = $service->getWeekMatrix($category, new \DateTimeImmutable('2026-09-14'), 7, $rules);

        self::assertCount(7, $rows);
        self::assertSame('2026-09-14', $rows[0]['date']->format('Y-m-d'));
        self::assertCount(7, $rows[0]['cells']);

        $saturday = $rows[5];
        self::assertSame('6', $saturday['date']->format('N'));
        // Two nights allowed, three too short, four allowed again.
        self::assertTrue($saturday['cells'][1]->isAllowed());
        self::assertFalse($saturday['cells'][2]->isAllowed());
        self::assertTrue($saturday['cells'][3]->isAllowed());

        foreach ($rows as $row) {
            foreach ($row['cells'] as $index => $cell) {
                $nights = $index + 1;
                $expected = $service->checkStay($category, $row['date'], $row['date']->modify(sprintf('+%d days', $nights)), $rules);
                self::assertSame($expected->isAllowed(), $cell->isAllowed());
            }
        }
    }

    public function testRuleWindowIsLoadedOnceForMultipleCategoriesAndResetAfterChanges(): void
    {
        $repo = $this->createMock(BookingRestrictionRuleRepository::class);
        $repo->expects(self::exactly(2))->method('findActiveForPeriod')->willReturn([]);
        $service = new BookingRestrictionService($repo, new ReservationPeriodService());
        $arrival = new \DateTimeImmutable('2026-09-13');
        $departure = new \DateTimeImmutable('2026-09-15');
        $service->checkStay($this->category(1), $arrival, $departure);
        $service->checkStay($this->category(2), $arrival, $departure);
        $service->reset();
        $service->checkStay($this->category(1), $arrival, $departure);
    }

    public function testWeekMatrixNeedsOnlyOneQuery(): void
    {
        $repo = $this->createMock(BookingRestrictionRuleRepository::class);
        $repo->expects(self::once())->method('findActiveForPeriod')->willReturn([]);
        $service = new BookingRestrictionService($repo, new ReservationPeriodService());
        $service->getWeekMatrix($this->category(1), new \DateTimeImmutable('2026-09-14'));
    }

    #[DataProvider('invalidPeriods')]
    public function testInvalidPeriodsAreRejectedBeforeQuerying(string $start, string $end): void
    {
        $repo = $this->createMock(BookingRestrictionRuleRepository::class);
        $repo->expects(self::never())->method('findActiveForPeriod');
        $service = new BookingRestrictionService($repo, new ReservationPeriodService());
        $this->expectException(InvalidReservationPeriodException::class);
        $service->checkStay(new RoomCategory(), new \DateTimeImmutable($start), new \DateTimeImmutable($end));
    }

    /** @return list<array{string, string}> */
    public static function invalidPeriods(): array
    {
        return [['2026-09-13', '2026-09-13'], ['2026-09-14', '2026-09-13'], ['2026-01-01', '2050-01-01']];
    }

    private function service(): BookingRestrictionService
    {
        return new BookingRestrictionService($this->createStub(BookingRestrictionRuleRepository::class), new ReservationPeriodService());
    }

    /** @param list<int> $weekdays */
    private function rule(Type $type, ?int $nights, array $weekdays = [1, 2, 3, 4, 5, 6, 7]): BookingRestrictionRule
    {
        $rule = new BookingRestrictionRule();
        $rule->setType($type);
        $rule->setMinNights($nights);
        $rule->setWeekdays($weekdays);

        return $rule;
    }

    private function period(Type $type, ?int $nights, string $start, string $end): BookingRestrictionRule
    {
        $rule = $this->rule($type, $nights);
        $rule->setPeriod(new \DateTimeImmutable($start), new \DateTimeImmutable($end));

        return $rule;
    }

    private function category(int $id): RoomCategory
    {
        $category = new RoomCategory();
        (new \ReflectionProperty(RoomCategory::class, 'id'))->setValue($category, $id);

        return $category;
    }
}
