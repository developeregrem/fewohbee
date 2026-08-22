<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Enum\PublicBookingMode;
use App\Entity\Enum\PublicBookingTheme;
use App\Entity\OnlineBookingConfig;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Repository\AppartmentRepository;
use App\Service\AvailabilityService;
use App\Service\OnlineBookingConfigService;
use App\Service\OnlineBookingRestrictionService;
use App\Service\PublicBookingCalendarService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Tests for the guest-facing availability calendar, with an emphasis on what the
 * anonymous surface is allowed to reveal.
 */
final class PublicBookingCalendarServiceTest extends TestCase
{
    private AppartmentRepository $appartmentRepository;
    private AvailabilityService $availabilityService;
    private OnlineBookingRestrictionService $restrictionService;

    public function testAvailabilityMarksOccupiedNightsAsTakenAndKeepsDepartureDayFree(): void
    {
        $room = $this->makeRoom(1, 'Doppelzimmer');
        $service = $this->makeService([$room], ['2026-09-02' => true, '2026-09-03' => true]);

        $result = $service->getAvailability((string) $room->getUuid(), '2026-09', 1);

        self::assertNotNull($result);
        self::assertTrue($result->nights['2026-09-01']);
        self::assertFalse($result->nights['2026-09-02']);
        self::assertFalse($result->nights['2026-09-03']);
        // The night after the last occupied one is free again — that is the
        // departure day, and it must stay bookable as the next arrival.
        self::assertTrue($result->nights['2026-09-04']);
    }

    public function testAvailabilityExposesNothingBeyondNightBooleans(): void
    {
        $room = $this->makeRoom(1, 'Doppelzimmer');
        $service = $this->makeService([$room], ['2026-09-02' => true]);

        $payload = $service->getAvailability((string) $room->getUuid(), '2026-09', 1)->toArray();

        self::assertSame(['room', 'from', 'toExclusive', 'nights'], array_keys($payload));
        self::assertSame((string) $room->getUuid(), $payload['room']);
        $distinctValues = array_unique(array_values($payload['nights']));
        sort($distinctValues);
        self::assertSame([0, 1], $distinctValues);
    }

    public function testAvailabilityIsRefusedForRoomsOutsideTheReleasedScope(): void
    {
        $released = $this->makeRoom(1, 'Doppelzimmer');
        $service = $this->makeService([$released], []);

        self::assertNull($service->getAvailability((string) Uuid::v4(), '2026-09', 1));
    }

    public function testAvailabilityIsRefusedWhenTheCalendarIsDisabled(): void
    {
        $room = $this->makeRoom(1, 'Doppelzimmer');
        $config = $this->makeConfig();
        $config->setMode(PublicBookingMode::SEARCH);
        $service = $this->makeService([$room], [], $config);

        self::assertNull($service->getAvailability((string) $room->getUuid(), '2026-09', 1));
    }

    public function testAvailabilityIsRefusedForTheClassicTheme(): void
    {
        $room = $this->makeRoom(1, 'Doppelzimmer');
        $config = $this->makeConfig();
        $config->setTheme(PublicBookingTheme::CLASSIC);
        $service = $this->makeService([$room], [], $config);

        self::assertNull($service->getAvailability((string) $room->getUuid(), '2026-09', 1));
    }

    public function testMalformedWindowIsRefused(): void
    {
        $room = $this->makeRoom(1, 'Doppelzimmer');
        $service = $this->makeService([$room], []);

        self::assertNull($service->getAvailability((string) $room->getUuid(), 'not-a-month', 1));
    }

    public function testWindowIsClampedToTheBookingHorizon(): void
    {
        $room = $this->makeRoom(1, 'Doppelzimmer');
        // Horizon ends in the middle of next month.
        $horizon = (new \DateTimeImmutable('today'))->modify('first day of next month')->modify('+14 days');
        $service = $this->makeService([$room], [], null, $horizon);

        $thisMonth = (new \DateTimeImmutable('today'))->format('Y-m');
        $result = $service->getAvailability((string) $room->getUuid(), $thisMonth, 3);

        self::assertNotNull($result);
        self::assertSame($horizon->format('Y-m-d'), $result->toExclusive);
    }

    public function testRoomsWithMultipleOccupancyAreNotOfferedInTheCalendar(): void
    {
        $normal = $this->makeRoom(1, 'Doppelzimmer');
        $dorm = $this->makeRoom(2, 'Schlafsaal');
        $dorm->setMultipleOccupancy(true);

        $service = $this->makeService([$normal, $dorm], []);

        $rooms = $service->getSelectableRooms();

        self::assertCount(1, $rooms);
        self::assertSame((string) $normal->getUuid(), $rooms[0]->uuid);
        // …and they cannot be addressed directly either.
        self::assertNull($service->getAvailability((string) $dorm->getUuid(), '2026-09', 1));
    }

    /**
     * The settings screen needs the eligible-room count even while the calendar is
     * switched off, so it can explain why enabling it would change nothing.
     */
    public function testEligibleRoomCountIgnoresTheEnabledFlagButNotTheOccupancyRule(): void
    {
        $dorm = $this->makeRoom(1, 'Schlafsaal');
        $dorm->setMultipleOccupancy(true);

        $config = $this->makeConfig();
        $config->setMode(PublicBookingMode::SEARCH);
        $service = $this->makeService([$dorm], [], $config);

        self::assertSame(0, $service->countEligibleRooms());

        $normal = $this->makeRoom(2, 'Doppelzimmer');
        self::assertSame(1, $this->makeService([$dorm, $normal], [], $config)->countEligibleRooms());
    }

    public function testRoomLabelUsesCategoryAndAddsTheNumberOnlyWhenAmbiguous(): void
    {
        $unique = $this->makeRoom(1, 'Ferienwohnung Seeblick', '11');
        $sharedA = $this->makeRoom(2, 'Doppelzimmer', '21');
        $sharedB = $this->makeRoom(3, 'Doppelzimmer', '22');

        $rooms = $this->makeService([$unique, $sharedA, $sharedB], [])->getSelectableRooms();

        self::assertSame('Ferienwohnung Seeblick', $rooms[0]->label);
        self::assertSame('Doppelzimmer – 21', $rooms[1]->label);
        self::assertSame('Doppelzimmer – 22', $rooms[2]->label);
    }

    private function makeConfig(): OnlineBookingConfig
    {
        $config = new OnlineBookingConfig();
        $config->setEnabled(true);
        $config->setTheme(PublicBookingTheme::MODERN);
        $config->setMode(PublicBookingMode::CALENDAR);

        return $config;
    }

    /**
     * @param Appartment[]        $rooms
     * @param array<string, true> $occupiedNights
     */
    private function makeService(
        array $rooms,
        array $occupiedNights,
        ?OnlineBookingConfig $config = null,
        ?\DateTimeImmutable $horizon = null,
    ): PublicBookingCalendarService {
        $config ??= $this->makeConfig();

        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getConfig')->willReturn($config);
        $configService->method('getAllowedRoomIds')->willReturn(array_map(static fn (Appartment $r) => $r->getId(), $rooms));
        $configService->method('getAllowedSubsidiaryIds')->willReturn([1]);

        $this->appartmentRepository = $this->createStub(AppartmentRepository::class);
        $this->appartmentRepository->method('findForPublicBooking')->willReturn($rooms);

        $this->availabilityService = $this->createStub(AvailabilityService::class);
        $this->availabilityService->method('getOccupiedNightsForRoom')->willReturn($occupiedNights);

        $this->restrictionService = $this->createStub(OnlineBookingRestrictionService::class);
        $this->restrictionService->method('getMaxDepartureDate')->willReturn($horizon);

        return new PublicBookingCalendarService(
            $configService,
            $this->appartmentRepository,
            $this->availabilityService,
            $this->restrictionService,
        );
    }

    private function makeRoom(int $id, string $categoryName, string $number = '1'): Appartment
    {
        $category = new RoomCategory();
        $category->setName($categoryName);

        $room = new Appartment();
        $room->setRoomCategory($category);
        $room->setNumber($number);
        $room->setBedsMax(2);
        $room->setDescription('internal note');
        $room->setObject(new Subsidiary());
        (new \ReflectionProperty(Appartment::class, 'id'))->setValue($room, $id);

        return $room;
    }
}
