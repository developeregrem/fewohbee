<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\OnlineBookingConfig;
use App\Entity\OnlineBookingRoomCategoryLimit;
use App\Entity\RoomCategory;
use App\Repository\OnlineBookingRoomCategoryLimitRepository;
use App\Service\OnlineBookingConfigService;
use App\Service\OnlineBookingRestrictionService;
use App\Service\BookingRestrictionService;
use PHPUnit\Framework\TestCase;

/**
 * Covers online room publication limits and the booking horizon.
 */
final class OnlineBookingRestrictionServiceTest extends TestCase
{
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

    // ── Min occupancy ──

    public function testMinOccupancyReturnsNullWhenNoLimit(): void
    {
        $category = $this->createCategory(1);
        $service = $this->createService(limitsByCategory: []);

        self::assertNull($service->getMinOccupancyForCategory($category));
    }

    public function testMinOccupancyReturnsNullWhenLimitHasNoOccupancy(): void
    {
        $category = $this->createCategory(1);
        $limit = $this->createLimit($category, 2);
        $service = $this->createService(limitsByCategory: [1 => $limit]);

        self::assertNull($service->getMinOccupancyForCategory($category));
    }

    public function testMinOccupancyReturnsConfiguredValue(): void
    {
        $category = $this->createCategory(1);
        $limit = $this->createLimit($category, null, 2);
        $service = $this->createService(limitsByCategory: [1 => $limit]);

        self::assertSame(2, $service->getMinOccupancyForCategory($category));
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

    // ── Helpers ──

    private function createCategory(int $id): RoomCategory
    {
        $category = new RoomCategory();
        $ref = new \ReflectionProperty(RoomCategory::class, 'id');
        $ref->setValue($category, $id);
        $category->setName('Category '.$id);

        return $category;
    }

    private function createLimit(RoomCategory $category, ?int $maxRooms = null, ?int $minOccupancy = null): OnlineBookingRoomCategoryLimit
    {
        $entity = new OnlineBookingRoomCategoryLimit();
        $entity->setRoomCategory($category);
        $entity->setMaxRooms($maxRooms);
        $entity->setMinOccupancy($minOccupancy);

        return $entity;
    }

    /**
     * @param array<int, OnlineBookingRoomCategoryLimit>    $limitsByCategory
     */
    private function createService(
        array $limitsByCategory = [],
        ?int $horizonMonths = null,
    ): OnlineBookingRestrictionService {
        $limitRepo = $this->createStub(OnlineBookingRoomCategoryLimitRepository::class);
        $limitRepo->method('findAllIndexedByCategory')->willReturn($limitsByCategory);

        $config = new OnlineBookingConfig();
        $config->setBookingHorizonMonths($horizonMonths);

        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getConfig')->willReturn($config);

        return new OnlineBookingRestrictionService($this->createStub(BookingRestrictionService::class), $limitRepo, $configService);
    }
}
