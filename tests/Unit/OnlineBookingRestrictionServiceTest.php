<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\OnlineBookingConfig;
use App\Entity\OnlineBookingMinStay;
use App\Entity\OnlineBookingMinStayOverride;
use App\Entity\OnlineBookingRoomCategoryLimit;
use App\Entity\RoomCategory;
use App\Repository\OnlineBookingMinStayOverrideRepository;
use App\Repository\OnlineBookingMinStayRepository;
use App\Repository\OnlineBookingRoomCategoryLimitRepository;
use App\Service\OnlineBookingConfigService;
use App\Service\OnlineBookingRestrictionService;
use PHPUnit\Framework\TestCase;

final class OnlineBookingRestrictionServiceTest extends TestCase
{
    // ── Weekend detection ──

    public function testMondayIsWeekday(): void
    {
        $service = $this->createService();
        // 2026-03-23 is a Monday
        self::assertFalse($service->isWeekendArrival(new \DateTimeImmutable('2026-03-23')));
    }

    public function testThursdayIsWeekday(): void
    {
        $service = $this->createService();
        // 2026-03-26 is a Thursday
        self::assertFalse($service->isWeekendArrival(new \DateTimeImmutable('2026-03-26')));
    }

    public function testFridayIsWeekend(): void
    {
        $service = $this->createService();
        // 2026-03-27 is a Friday
        self::assertTrue($service->isWeekendArrival(new \DateTimeImmutable('2026-03-27')));
    }

    public function testSundayIsWeekend(): void
    {
        $service = $this->createService();
        // 2026-03-22 is a Sunday
        self::assertTrue($service->isWeekendArrival(new \DateTimeImmutable('2026-03-22')));
    }

    // ── Default min nights (no overrides) ──

    public function testNoConfigReturnsOneNight(): void
    {
        $category = $this->createCategory(1);
        $service = $this->createService(minStayByCategory: []);

        self::assertSame(1, $service->getEffectiveMinNights($category, new \DateTimeImmutable('2026-03-23')));
    }

    public function testWeekdayDefaultMinNights(): void
    {
        $category = $this->createCategory(1);
        $minStay = $this->createMinStay($category, weekday: 3, weekend: 5);
        $service = $this->createService(minStayByCategory: [1 => $minStay]);

        // Monday arrival → weekday
        self::assertSame(3, $service->getEffectiveMinNights($category, new \DateTimeImmutable('2026-03-23')));
    }

    public function testWeekendDefaultMinNights(): void
    {
        $category = $this->createCategory(1);
        $minStay = $this->createMinStay($category, weekday: 3, weekend: 5);
        $service = $this->createService(minStayByCategory: [1 => $minStay]);

        // Friday arrival → weekend
        self::assertSame(5, $service->getEffectiveMinNights($category, new \DateTimeImmutable('2026-03-27')));
    }

    public function testNullWeekdayFallsBackToOne(): void
    {
        $category = $this->createCategory(1);
        $minStay = $this->createMinStay($category, weekday: null, weekend: 5);
        $service = $this->createService(minStayByCategory: [1 => $minStay]);

        self::assertSame(1, $service->getEffectiveMinNights($category, new \DateTimeImmutable('2026-03-23')));
    }

    public function testNullWeekendFallsBackToOne(): void
    {
        $category = $this->createCategory(1);
        $minStay = $this->createMinStay($category, weekday: 3, weekend: null);
        $service = $this->createService(minStayByCategory: [1 => $minStay]);

        self::assertSame(1, $service->getEffectiveMinNights($category, new \DateTimeImmutable('2026-03-27')));
    }

    // ── Overrides ──

    public function testOverrideTakesPrecedenceOverDefault(): void
    {
        $category = $this->createCategory(1);
        $minStay = $this->createMinStay($category, weekday: 2, weekend: 3);
        $override = $this->createOverride($category, '2026-03-01', '2026-03-31', 7);

        $service = $this->createService(
            minStayByCategory: [1 => $minStay],
            overrides: [$override],
        );

        self::assertSame(7, $service->getEffectiveMinNights($category, new \DateTimeImmutable('2026-03-15')));
    }

    public function testOverrideWithNullCategoryAppliesToAll(): void
    {
        $category = $this->createCategory(1);
        $override = $this->createOverride(null, '2026-07-01', '2026-08-31', 5);

        $service = $this->createService(overrides: [$override]);

        self::assertSame(5, $service->getEffectiveMinNights($category, new \DateTimeImmutable('2026-07-15')));
    }

    public function testOverrideForDifferentCategoryDoesNotApply(): void
    {
        $category1 = $this->createCategory(1);
        $category2 = $this->createCategory(2);
        $override = $this->createOverride($category2, '2026-07-01', '2026-08-31', 7);

        $service = $this->createService(overrides: [$override]);

        // Category 1 has no override, no default → 1
        self::assertSame(1, $service->getEffectiveMinNights($category1, new \DateTimeImmutable('2026-07-15')));
    }

    public function testHighestOverrideWinsOnOverlap(): void
    {
        $category = $this->createCategory(1);
        $override1 = $this->createOverride($category, '2026-07-01', '2026-08-31', 3);
        $override2 = $this->createOverride(null, '2026-07-15', '2026-08-15', 5);
        $override3 = $this->createOverride($category, '2026-07-20', '2026-07-30', 7);

        $service = $this->createService(overrides: [$override1, $override2, $override3]);

        // All three overlap on 2026-07-25 → highest (7) wins
        self::assertSame(7, $service->getEffectiveMinNights($category, new \DateTimeImmutable('2026-07-25')));
    }

    public function testOverrideEndDateCoversLastDayBefore(): void
    {
        $category = $this->createCategory(1);
        $override = $this->createOverride($category, '2026-07-01', '2026-07-15', 5);

        $service = $this->createService(overrides: [$override]);

        // Arrival on 2026-07-14 → covered (end > arrival)
        self::assertSame(5, $service->getEffectiveMinNights($category, new \DateTimeImmutable('2026-07-14')));
    }

    public function testOverrideEndDateDoesNotCoverArrivalOnEndDate(): void
    {
        $category = $this->createCategory(1);

        // No overrides returned for this arrival date (repository filters by end > arrival)
        $service = $this->createService(overrides: []);

        // Arrival on 2026-07-15 → NOT covered (end = arrival, not >)
        self::assertSame(1, $service->getEffectiveMinNights($category, new \DateTimeImmutable('2026-07-15')));
    }

    // ── Room category limits ──

    public function testMaxRoomsReturnsNullWhenNoLimit(): void
    {
        $category = $this->createCategory(1);
        $service = $this->createService(limitsByCategory: []);

        self::assertNull($service->getMaxRoomsForCategory($category));
    }

    public function testMaxRoomsReturnsConfiguredValue(): void
    {
        $category = $this->createCategory(1);
        $limit = $this->createLimit($category, 2);
        $service = $this->createService(limitsByCategory: [1 => $limit]);

        self::assertSame(2, $service->getMaxRoomsForCategory($category));
    }

    // ── Booking horizon ──

    public function testMaxDepartureDateReturnsNullWhenNoHorizon(): void
    {
        $service = $this->createService(horizonMonths: null);

        self::assertNull($service->getMaxDepartureDate());
    }

    public function testMaxDepartureDateCalculatesCorrectly(): void
    {
        $service = $this->createService(horizonMonths: 12);

        $expected = (new \DateTimeImmutable('today'))->modify('+12 months');
        self::assertEquals($expected, $service->getMaxDepartureDate());
    }

    // ── isStayLongEnough ──

    public function testStayLongEnoughPasses(): void
    {
        $category = $this->createCategory(1);
        $minStay = $this->createMinStay($category, weekday: 3, weekend: 3);
        $service = $this->createService(minStayByCategory: [1 => $minStay]);

        self::assertTrue($service->isStayLongEnough($category, new \DateTimeImmutable('2026-03-23'), 3));
        self::assertTrue($service->isStayLongEnough($category, new \DateTimeImmutable('2026-03-23'), 5));
    }

    public function testStayLongEnoughFails(): void
    {
        $category = $this->createCategory(1);
        $minStay = $this->createMinStay($category, weekday: 3, weekend: 3);
        $service = $this->createService(minStayByCategory: [1 => $minStay]);

        self::assertFalse($service->isStayLongEnough($category, new \DateTimeImmutable('2026-03-23'), 2));
    }

    // ── Helpers ──

    private function createCategory(int $id): RoomCategory
    {
        $category = new RoomCategory();
        $ref = new \ReflectionProperty(RoomCategory::class, 'id');
        $ref->setValue($category, $id);
        $category->setName('Category '.$id);

        return $category;
    }

    private function createMinStay(RoomCategory $category, ?int $weekday, ?int $weekend): OnlineBookingMinStay
    {
        $entity = new OnlineBookingMinStay();
        $entity->setRoomCategory($category);
        $entity->setMinNightsWeekday($weekday);
        $entity->setMinNightsWeekend($weekend);

        return $entity;
    }

    private function createOverride(?RoomCategory $category, string $start, string $end, int $minNights): OnlineBookingMinStayOverride
    {
        $entity = new OnlineBookingMinStayOverride();
        $entity->setRoomCategory($category);
        $entity->setStartDate(new \DateTime($start));
        $entity->setEndDate(new \DateTime($end));
        $entity->setMinNights($minNights);

        return $entity;
    }

    private function createLimit(RoomCategory $category, int $maxRooms): OnlineBookingRoomCategoryLimit
    {
        $entity = new OnlineBookingRoomCategoryLimit();
        $entity->setRoomCategory($category);
        $entity->setMaxRooms($maxRooms);

        return $entity;
    }

    /**
     * @param array<int, OnlineBookingMinStay>             $minStayByCategory
     * @param OnlineBookingMinStayOverride[]                $overrides
     * @param array<int, OnlineBookingRoomCategoryLimit>    $limitsByCategory
     */
    private function createService(
        array $minStayByCategory = [],
        array $overrides = [],
        array $limitsByCategory = [],
        ?int $horizonMonths = null,
    ): OnlineBookingRestrictionService {
        $minStayRepo = $this->createStub(OnlineBookingMinStayRepository::class);
        $minStayRepo->method('findAllIndexedByCategory')->willReturn($minStayByCategory);

        $overrideRepo = $this->createStub(OnlineBookingMinStayOverrideRepository::class);
        $overrideRepo->method('findActiveForArrival')->willReturn($overrides);

        $limitRepo = $this->createStub(OnlineBookingRoomCategoryLimitRepository::class);
        $limitRepo->method('findAllIndexedByCategory')->willReturn($limitsByCategory);

        $config = new OnlineBookingConfig();
        $config->setBookingHorizonMonths($horizonMonths);

        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getConfig')->willReturn($config);

        return new OnlineBookingRestrictionService($minStayRepo, $overrideRepo, $limitRepo, $configService);
    }
}
