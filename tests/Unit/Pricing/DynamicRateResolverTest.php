<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Dto\Pricing\NightAdjustment;
use App\Entity\Appartment;
use App\Entity\AppSettings;
use App\Entity\DayPrice;
use App\Entity\Enum\PriceRounding;
use App\Entity\Enum\PriceRuleCondition;
use App\Entity\PriceRule;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Repository\PriceRuleRepository;
use App\Repository\SubsidiaryRepository;
use App\Service\AppSettingsService;
use App\Service\AvailabilityService;
use App\Service\Pricing\DayPriceResolver;
use App\Service\Pricing\DynamicRateResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class DynamicRateResolverTest extends TestCase
{
    // A Friday.
    private const TODAY = '2026-10-02';

    public function testAWeekendRuleChangesFridayAndSaturdayNightsOnly(): void
    {
        $weekend = $this->rule(10.0, weekdays: [5, 6]);

        $adjustments = $this->resolver([$weekend])->adjustments($this->room(1, 3), $this->day('2026-10-02'), $this->day('2026-10-06'));

        self::assertSame(['2026-10-02', '2026-10-03'], array_keys($adjustments));
        self::assertSame(10.0, $adjustments['2026-10-02']->percent);
    }

    public function testADateRangeCoversItsFirstAndLastNight(): void
    {
        $fair = $this->rule(30.0);
        $fair->setPeriod($this->day('2026-10-10'), $this->day('2026-10-13'));

        $adjustments = $this->resolver([$fair])->adjustments($this->room(1, 3), $this->day('2026-10-09'), $this->day('2026-10-15'));

        self::assertSame(['2026-10-10', '2026-10-11', '2026-10-12'], array_keys($adjustments));
    }

    public function testLeadTimeIsCountedFromToday(): void
    {
        $lastMinute = $this->rule(-10.0, PriceRuleCondition::LAST_MINUTE, days: 2);
        $earlyBird = $this->rule(-5.0, PriceRuleCondition::EARLY_BIRD, days: 4);

        $adjustments = $this->resolver([$lastMinute, $earlyBird])->adjustments($this->room(1, 3), $this->day('2026-10-02'), $this->day('2026-10-08'));

        self::assertSame(-10.0, $adjustments['2026-10-02']->percent);
        self::assertSame(-10.0, $adjustments['2026-10-04']->percent);
        self::assertArrayNotHasKey('2026-10-05', $adjustments);
        self::assertSame(-5.0, $adjustments['2026-10-06']->percent);
    }

    public function testADayPriceTakesThePlaceOfTheRulesOnItsNight(): void
    {
        $dayPrice = new NightAdjustment(25.0, [], false, $this->createStub(DayPrice::class));
        $dayPrices = $this->createStub(DayPriceResolver::class);
        $dayPrices->method('adjustments')->willReturn(['2026-10-03' => $dayPrice]);

        $adjustments = $this->resolver([$this->rule(10.0)], dayPrices: $dayPrices)->adjustments($this->room(1, 3), $this->day('2026-10-02'), $this->day('2026-10-05'));

        self::assertSame(10.0, $adjustments['2026-10-02']->percent);
        self::assertSame($dayPrice, $adjustments['2026-10-03']);
        self::assertSame(10.0, $adjustments['2026-10-04']->percent);
    }

    public function testADayPriceIsNotRounded(): void
    {
        $settings = new AppSettings();
        $dayPrice = new NightAdjustment(0.6666666, [], false, $this->createStub(DayPrice::class));

        // 151 € for two guests against a list price of 75 € per head: exactly 75.50 € per head.
        self::assertSame('75.50', $this->resolver([], settings: $settings)->apply('75.00', $dayPrice));
    }

    public function testNightsBeforeTodayAreNeverChanged(): void
    {
        $adjustments = $this->resolver([$this->rule(10.0)])->adjustments($this->room(1, 3), $this->day('2026-09-28'), $this->day('2026-10-04'));

        self::assertSame(['2026-10-02', '2026-10-03'], array_keys($adjustments));
    }

    public function testMatchingRulesAreAddedUpAndHeldWithinTheLimits(): void
    {
        $settings = new AppSettings();
        $settings->setPriceChangeLimits(-30, 25);
        $rules = [$this->rule(10.0), $this->rule(20.0)];

        $night = $this->resolver($rules, settings: $settings)->adjustments($this->room(1, 3), $this->day('2026-10-02'), $this->day('2026-10-03'))['2026-10-02'];

        self::assertSame(25.0, $night->percent);
        self::assertTrue($night->limited);
        self::assertSame($rules, $night->rules);
    }

    public function testARuleOnlyAppliesWithinItsScope(): void
    {
        $here = $this->subsidiary(1);
        $elsewhere = $this->subsidiary(2);
        $double = $this->category(3);
        $selected = $this->rule(10.0);
        $selected->setSubsidiaries(false, [$here]);
        $selected->setCategories(false, [$double]);
        // A selection emptied in the form must not turn into "all".
        $emptied = $this->rule(50.0);
        $emptied->setSubsidiaries(false, []);

        $resolver = $this->resolver([$selected, $emptied]);
        $window = [$this->day('2026-10-02'), $this->day('2026-10-03')];

        self::assertSame(10.0, $resolver->adjustments($this->roomIn($here, $double), ...$window)['2026-10-02']->percent);
        self::assertSame([], $resolver->adjustments($this->roomIn($elsewhere, $double), ...$window));
        self::assertSame([], $resolver->adjustments($this->roomIn($here, $this->category(4)), ...$window));
    }

    public function testOccupancyCountsBookedAgainstSellableRoomsWithoutTheBookingItself(): void
    {
        $high = $this->rule(15.0, PriceRuleCondition::OCCUPANCY_HIGH, occupancy: 75);
        $availability = $this->createMock(AvailabilityService::class);
        $availability->expects(self::once())
            ->method('getRoomNightsPerDay')
            ->with(1, null, $this->day('2026-10-02'), $this->day('2026-10-05'), 42)
            ->willReturn([
                '2026-10-02' => ['rooms' => 5, 'booked' => 3, 'blocked' => 1, 'available' => 1],
                '2026-10-03' => ['rooms' => 5, 'booked' => 2, 'blocked' => 1, 'available' => 2],
                '2026-10-04' => ['rooms' => 1, 'booked' => 0, 'blocked' => 1, 'available' => 0],
            ]);

        $adjustments = $this->resolver([$high], $availability)
            ->adjustments($this->room(1, 3), $this->day('2026-10-02'), $this->day('2026-10-05'), excludingReservationId: 42);

        // 3 of 4 sellable rooms = 75 %; 2 of 4 = 50 %; nothing sellable = no occupancy at all.
        self::assertSame(['2026-10-02'], array_keys($adjustments));
    }

    public function testSubsidiariesCountedTogetherAddUpTheirRooms(): void
    {
        // Two subsidiaries that are really the floors of one house.
        $high = $this->rule(15.0, PriceRuleCondition::OCCUPANCY_HIGH, occupancy: 75);
        $high->setOccupancyAcrossSubsidiaries(true);
        $perFloor = [
            1 => ['2026-10-02' => ['rooms' => 2, 'booked' => 2, 'blocked' => 0, 'available' => 0]],
            2 => ['2026-10-02' => ['rooms' => 2, 'booked' => 0, 'blocked' => 0, 'available' => 2]],
        ];
        $availability = $this->createStub(AvailabilityService::class);
        $availability->method('getRoomNightsPerDay')->willReturnCallback(static fn (int $subsidiaryId): array => $perFloor[$subsidiaryId]);
        $window = [$this->day('2026-10-02'), $this->day('2026-10-03')];

        // The first floor is full, but the house as a whole is only half booked.
        self::assertSame([], $this->resolver([$high], $availability)->adjustments($this->room(1, 3), ...$window));

        $high->setOccupancyAcrossSubsidiaries(false);
        self::assertArrayHasKey('2026-10-02', $this->resolver([$high], $availability)->adjustments($this->room(1, 3), ...$window));
    }

    public function testLowOccupancyAlsoNeedsTheNightToBeNear(): void
    {
        $low = $this->rule(-10.0, PriceRuleCondition::OCCUPANCY_LOW, days: 1, occupancy: 40);
        $availability = $this->createStub(AvailabilityService::class);
        $availability->method('getRoomNightsPerDay')->willReturn([
            '2026-10-02' => ['rooms' => 4, 'booked' => 1, 'blocked' => 0, 'available' => 3],
            '2026-10-03' => ['rooms' => 4, 'booked' => 2, 'blocked' => 0, 'available' => 2],
            '2026-10-04' => ['rooms' => 4, 'booked' => 0, 'blocked' => 0, 'available' => 4],
        ]);

        $adjustments = $this->resolver([$low], $availability)->adjustments($this->room(1, 3), $this->day('2026-10-02'), $this->day('2026-10-05'));

        self::assertSame(['2026-10-02'], array_keys($adjustments));
    }

    public function testTheChangedPriceIsRoundedAsConfigured(): void
    {
        $adjustment = new NightAdjustment(12.0, []);
        $settings = new AppSettings();

        self::assertSame('49.00', $this->resolver([], settings: $settings)->apply('44.00', $adjustment));
        $settings->setPriceChangeRounding(PriceRounding::CENT);
        self::assertSame('49.28', $this->resolver([], settings: $settings)->apply('44.00', $adjustment));
    }

    /** @param list<PriceRule> $rules */
    private function resolver(array $rules, ?AvailabilityService $availability = null, ?AppSettings $settings = null, ?DayPriceResolver $dayPrices = null): DynamicRateResolver
    {
        $repository = $this->createStub(PriceRuleRepository::class);
        $repository->method('findEnabled')->willReturn($rules);
        $settingsService = $this->createStub(AppSettingsService::class);
        $settingsService->method('getSettings')->willReturn($settings ?? new AppSettings());
        $subsidiaries = $this->createStub(SubsidiaryRepository::class);
        $subsidiaries->method('loadAllIds')->willReturn([1, 2]);

        return new DynamicRateResolver(
            $repository,
            $availability ?? $this->createStub(AvailabilityService::class),
            $settingsService,
            new MockClock(self::TODAY.' 15:00:00'),
            $subsidiaries,
            $dayPrices ?? $this->createStub(DayPriceResolver::class),
        );
    }

    private function room(int $subsidiaryId, int $categoryId): Appartment
    {
        return $this->roomIn($this->subsidiary($subsidiaryId), $this->category($categoryId));
    }

    private function roomIn(Subsidiary $subsidiary, RoomCategory $category): Appartment
    {
        $room = new Appartment();
        $room->setObject($subsidiary);
        $room->setRoomCategory($category);

        return $room;
    }

    /** @param list<int> $weekdays */
    private function rule(float $percent, PriceRuleCondition $condition = PriceRuleCondition::ALWAYS, ?int $days = null, ?int $occupancy = null, array $weekdays = [1, 2, 3, 4, 5, 6, 7]): PriceRule
    {
        $rule = new PriceRule();
        $rule->setName('rule');
        $rule->setPercent($percent);
        $rule->setCondition($condition, $days, $occupancy);
        $rule->setWeekdays($weekdays);

        return $rule;
    }

    private function subsidiary(int $id): Subsidiary
    {
        $subsidiary = new Subsidiary();
        (new \ReflectionProperty(Subsidiary::class, 'id'))->setValue($subsidiary, $id);

        return $subsidiary;
    }

    private function category(int $id): RoomCategory
    {
        $category = new RoomCategory();
        (new \ReflectionProperty(RoomCategory::class, 'id'))->setValue($category, $id);

        return $category;
    }

    private function day(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }
}
