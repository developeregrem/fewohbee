<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\BookingRestrictionRule;
use App\Entity\Enum\BookingRestrictionType;
use App\Entity\OnlineBookingConfig;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Repository\AppartmentRepository;
use App\Repository\BookingRestrictionRuleRepository;
use App\Repository\OnlineBookingRoomCategoryLimitRepository;
use App\Repository\ReservationRepository;
use App\Service\AvailabilityService;
use App\Service\BookingRestrictionService;
use App\Service\OnlineBookingConfigService;
use App\Service\OnlineBookingRestrictionService;
use App\Service\PublicAvailabilityService;
use App\Service\PublicPricingService;
use App\Service\ReservationPeriodService;
use App\Service\RoomCategoryImageService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Exercises both public booking paths against the real shared restriction resolver. */
final class PublicAvailabilityBookingRulesTest extends TestCase
{
    public function testSundayArrivalIsRejectedWhenAnOccupiedWeeknightRequiresFourNights(): void
    {
        $room = $this->room(1, 1);
        $rule = $this->rule(BookingRestrictionType::MIN_STAY_THROUGH, 4, [1, 2, 3, 4]);
        $service = $this->service([$room], [$rule]);
        $arrival = new \DateTimeImmutable('2026-09-13');
        $tooShort = new \DateTimeImmutable('2026-09-15');
        $longEnough = new \DateTimeImmutable('2026-09-17');

        self::assertSame([], $service->getAvailability($arrival, $tooShort, 1, 1));
        self::assertSame([], $service->getAvailabilityForRoom($room, $arrival, $tooShort));
        self::assertCount(1, $service->getAvailability($arrival, $longEnough, 1, 1));
        self::assertCount(1, $service->getAvailabilityForRoom($room, $arrival, $longEnough));
    }

    public function testClosedDepartureDayIsRejectedOnBothPublicPaths(): void
    {
        // Tuesday is closed for departures; the stay is long enough but must still fail.
        $room = $this->room(1, 1);
        $service = $this->service([$room], [$this->rule(BookingRestrictionType::CLOSED_TO_DEPARTURE, null, [2])]);
        $arrival = new \DateTimeImmutable('2026-09-13');
        $closedDeparture = new \DateTimeImmutable('2026-09-15');
        $openDeparture = new \DateTimeImmutable('2026-09-16');

        self::assertSame([], $service->getAvailability($arrival, $closedDeparture, 1, 1));
        self::assertSame([], $service->getAvailabilityForRoom($room, $arrival, $closedDeparture));
        self::assertCount(1, $service->getAvailability($arrival, $openDeparture, 1, 1));
        self::assertCount(1, $service->getAvailabilityForRoom($room, $arrival, $openDeparture));
    }

    public function testRulesScopedToOtherCategoriesLeaveTheOfferUntouched(): void
    {
        $room = $this->room(1, 1);
        $rule = $this->rule(BookingRestrictionType::MIN_STAY_ARRIVAL, 4);
        $rule->setAllCategories(false);
        $rule->setCategories([$this->createStub(RoomCategory::class)]);
        $service = $this->service([$room], [$rule]);

        self::assertCount(1, $service->getAvailability(new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-09-15'), 1, 1));
    }

    /** @param list<int> $weekdays */
    private function rule(BookingRestrictionType $type, ?int $nights, array $weekdays = [1, 2, 3, 4, 5, 6, 7]): BookingRestrictionRule
    {
        $rule = new BookingRestrictionRule();
        $rule->setType($type);
        $rule->setMinNights($nights);
        $rule->setWeekdays($weekdays);

        return $rule;
    }

    /** @param list<Appartment> $rooms
     * @param list<BookingRestrictionRule> $rules
     */
    private function service(array $rooms, array $rules): PublicAvailabilityService
    {
        $config = $this->createStub(OnlineBookingConfigService::class);
        $config->method('getConfig')->willReturn(new OnlineBookingConfig());
        $config->method('getAllowedSubsidiaryIds')->willReturn([1, 2]);
        $config->method('getAllowedRoomIds')->willReturn([1, 2]);
        $ruleRepository = $this->createStub(BookingRestrictionRuleRepository::class);
        $ruleRepository->method('findActiveForPeriod')->willReturn($rules);
        $restrictions = new OnlineBookingRestrictionService(
            new BookingRestrictionService($ruleRepository, new ReservationPeriodService()),
            $this->createStub(OnlineBookingRoomCategoryLimitRepository::class),
            $config,
        );
        $roomRepository = $this->createStub(AppartmentRepository::class);
        $roomRepository->method('findForPublicBooking')->willReturn($rooms);
        $availability = $this->createStub(AvailabilityService::class);
        $availability->method('isRoomAvailable')->willReturn(true);
        $availability->method('isRoomAvailableFromPreloadedOccupancy')->willReturn(true);
        $pricing = $this->createStub(PublicPricingService::class);
        $pricing->method('getOccupancyPrices')->willReturn([1 => [
            'persons' => 1, 'totalPrice' => 100.0, 'totalPriceFormatted' => '100.00',
        ]]);

        return new PublicAvailabilityService(
            $roomRepository, $this->createStub(ReservationRepository::class), $config, $restrictions,
            $pricing, $this->createStub(RoomCategoryImageService::class),
            $this->createStub(TranslatorInterface::class), $availability,
        );
    }

    private function room(int $id, int $propertyId): Appartment
    {
        $category = $this->createStub(RoomCategory::class);
        $category->method('getId')->willReturn(1);
        $category->method('getName')->willReturn('Double');
        $property = $this->createStub(Subsidiary::class);
        $property->method('getId')->willReturn($propertyId);
        $room = new Appartment();
        $room->setId($id);
        $room->setBedsMax(2);
        $room->setNumber((string) $id);
        $room->setRoomCategory($category);
        $room->setObject($property);

        return $room;
    }
}
