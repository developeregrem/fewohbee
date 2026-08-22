<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Reservation;
use App\Entity\RoomBlock;
use App\Entity\Subsidiary;
use App\Repository\AppartmentRepository;
use App\Repository\ReservationRepository;
use App\Repository\RoomBlockRepository;
use App\Service\AvailabilityService;
use PHPUnit\Framework\TestCase;

final class AvailabilityServiceTest extends TestCase
{
    public function testFreeRoomIsAvailable(): void
    {
        $service = $this->makeService([], []);
        $room = self::makeRoom(1);

        self::assertTrue($service->isRoomAvailable($room, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-05')));
    }

    public function testInactiveRoomIsUnavailable(): void
    {
        $service = $this->makeService([], []);
        $room = self::makeRoom(1)->setActive(false);

        self::assertFalse($service->isRoomAvailable($room, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-05')));
    }

    public function testRoomIsUnavailableWhenReservationExceedsBedCapacity(): void
    {
        $service = $this->makeService([], []);
        $room = self::makeRoom(1);

        self::assertFalse($service->isRoomAvailable(
            $room,
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-05'),
            3,
        ));
        self::assertFalse($service->hasCapacity($room, 3));
        self::assertTrue($service->hasCapacity($room, 2));
    }

    public function testOverlappingReservationMakesRoomUnavailable(): void
    {
        $service = $this->makeService([self::makeReservation(10, '2026-08-02', '2026-08-04')], []);
        $room = self::makeRoom(1);

        self::assertFalse($service->isRoomAvailable($room, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-05')));
    }

    public function testIgnoredReservationIsFilteredOut(): void
    {
        $reservation = self::makeReservation(10, '2026-08-02', '2026-08-04');
        $service = $this->makeService([$reservation], []);
        $room = self::makeRoom(1);

        self::assertTrue($service->isRoomAvailable($room, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-05'), 0, $reservation));
    }

    public function testBlockMakesRoomUnavailable(): void
    {
        $service = $this->makeService([], [self::makeBlock(self::makeRoom(1), '2026-08-02', '2026-08-04')]);
        $room = self::makeRoom(1);

        self::assertFalse($service->isRoomAvailable($room, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-05')));
    }

    public function testBlockOverridesMultipleOccupancyBedMath(): void
    {
        $room = self::makeRoom(1);
        $room->setMultipleOccupancy(true);
        $room->setBedsMax(6);
        $service = $this->makeService([], [self::makeBlock($room, '2026-08-02', '2026-08-04')]);

        self::assertFalse($service->isRoomAvailable($room, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-05'), 1));
    }

    public function testMultipleOccupancyBedMathIsPreserved(): void
    {
        $room = self::makeRoom(1);
        $room->setMultipleOccupancy(true);
        $room->setBedsMax(4);
        $existing = self::makeReservation(10, '2026-08-01', '2026-08-05');
        $existing->setPersons(2);
        $service = $this->makeService([$existing], []);

        self::assertTrue($service->isRoomAvailable($room, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-05'), 2));
        self::assertFalse($service->isRoomAvailable($room, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-05'), 3));
    }

    public function testMultipleOccupancyCountsOnlySimultaneouslyOverlappingReservations(): void
    {
        $room = self::makeRoom(1);
        $room->setMultipleOccupancy(true);
        $room->setBedsMax(5);

        $first = self::makeReservation(10, '2026-08-07', '2026-08-09');
        $first->setPersons(1);
        $second = self::makeReservation(11, '2026-08-09', '2026-08-11');
        $second->setPersons(2);
        $service = $this->makeService([$first, $second], []);

        self::assertTrue($service->isRoomAvailable(
            $room,
            new \DateTimeImmutable('2026-08-08'),
            new \DateTimeImmutable('2026-08-10'),
            3,
        ));
        self::assertFalse($service->isRoomAvailable(
            $room,
            new \DateTimeImmutable('2026-08-08'),
            new \DateTimeImmutable('2026-08-10'),
            4,
        ));
    }

    public function testMultipleOccupancyUsesPeakAcrossConcurrentReservations(): void
    {
        $room = self::makeRoom(1);
        $room->setMultipleOccupancy(true);
        $room->setBedsMax(6);

        $spanning = self::makeReservation(10, '2026-08-07', '2026-08-11');
        $spanning->setPersons(1);
        $first = self::makeReservation(11, '2026-08-07', '2026-08-09');
        $first->setPersons(2);
        $second = self::makeReservation(12, '2026-08-09', '2026-08-11');
        $second->setPersons(4);
        $service = $this->makeService([$spanning, $first, $second], []);

        self::assertTrue($service->isRoomAvailable(
            $room,
            new \DateTimeImmutable('2026-08-08'),
            new \DateTimeImmutable('2026-08-10'),
            1,
        ));
        self::assertFalse($service->isRoomAvailable(
            $room,
            new \DateTimeImmutable('2026-08-08'),
            new \DateTimeImmutable('2026-08-10'),
            2,
        ));
    }

    public function testCountAvailablePerDayMixesReservationsAndBlocksDeduplicated(): void
    {
        $roomA = self::makeRoom(1);
        $roomB = self::makeRoom(2);

        $reservationRepo = $this->createStub(ReservationRepository::class);
        // room A occupied 08-01..08-03 (nights 1+2)
        $reservationRepo->method('loadBlockingSpansForPeriod')->willReturn([
            ['appartmentId' => 1, 'startDate' => '2026-08-01', 'endDate' => '2026-08-03'],
        ]);

        $blockRepo = $this->createStub(RoomBlockRepository::class);
        // room A ALSO blocked 08-02..08-04 (dedupe on 08-02) and room B blocked 08-01..08-02
        $blockRepo->method('findForPeriod')->willReturn([
            self::makeBlock($roomA, '2026-08-02', '2026-08-04'),
            self::makeBlock($roomB, '2026-08-01', '2026-08-02'),
        ]);

        $apartmentRepo = $this->createStub(AppartmentRepository::class);
        $apartmentRepo->method('findAllByProperty')->willReturn([$roomA, $roomB]);

        $service = new AvailabilityService($reservationRepo, $blockRepo, $apartmentRepo);
        $result = $service->countAvailablePerDay('all', null, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-05'));

        self::assertSame([
            '2026-08-01' => 0, // A reserved, B blocked
            '2026-08-02' => 1, // A reserved+blocked (dedupe), B free
            '2026-08-03' => 1, // A blocked
            '2026-08-04' => 2,
        ], $result);
    }

    public function testCountAvailablePerDayFiltersByCategory(): void
    {
        $category = new \App\Entity\RoomCategory();
        $ref = new \ReflectionProperty(\App\Entity\RoomCategory::class, 'id');
        $ref->setValue($category, 7);
        $roomA = self::makeRoom(1)->setRoomCategory($category);
        $roomB = self::makeRoom(2); // no category

        $reservationRepo = $this->createStub(ReservationRepository::class);
        $reservationRepo->method('loadBlockingSpansForPeriod')->willReturn([]);
        $blockRepo = $this->createStub(RoomBlockRepository::class);
        $blockRepo->method('findForPeriod')->willReturn([self::makeBlock($roomB, '2026-08-01', '2026-08-02')]);
        $apartmentRepo = $this->createStub(AppartmentRepository::class);
        $apartmentRepo->method('findAllByProperty')->willReturn([$roomA, $roomB]);

        $service = new AvailabilityService($reservationRepo, $blockRepo, $apartmentRepo);
        $result = $service->countAvailablePerDay('all', 7, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-02'));

        // roomB (blocked, other category) must not affect the count; only roomA is in scope
        self::assertSame(['2026-08-01' => 1], $result);
    }

    public function testGetBlockedPerDayClipsToWindow(): void
    {
        $room = self::makeRoom(1);
        $room->setBedsMax(3);

        $blockRepo = $this->createStub(RoomBlockRepository::class);
        $blockRepo->method('findForPeriod')->willReturn([
            self::makeBlock($room, '2026-07-30', '2026-08-03'), // starts before window
        ]);

        $service = new AvailabilityService(
            $this->createStub(ReservationRepository::class),
            $blockRepo,
            $this->createStub(AppartmentRepository::class)
        );
        $result = $service->getBlockedPerDay('all', new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-05'));

        self::assertSame(['rooms' => 1, 'beds' => 3], $result['2026-08-01']);
        self::assertSame(['rooms' => 1, 'beds' => 3], $result['2026-08-02']);
        self::assertSame(['rooms' => 0, 'beds' => 0], $result['2026-08-03']); // exclusive end
        self::assertSame(['rooms' => 0, 'beds' => 0], $result['2026-08-04']);
    }

    public function testOccupiedNightsForRoomTreatsDepartureDayAsFree(): void
    {
        $room = self::makeRoom(1);
        $room->setObject(new Subsidiary());

        $reservationRepo = $this->createStub(ReservationRepository::class);
        $reservationRepo->method('loadBlockingSpansForPeriod')->willReturn([
            ['appartmentId' => 1, 'startDate' => '2026-08-02', 'endDate' => '2026-08-04'],
        ]);

        $service = new AvailabilityService(
            $reservationRepo,
            $this->createStub(RoomBlockRepository::class),
            $this->createStub(AppartmentRepository::class)
        );

        $occupied = $service->getOccupiedNightsForRoom($room, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-06'));

        self::assertArrayNotHasKey('2026-08-01', $occupied);
        self::assertArrayHasKey('2026-08-02', $occupied);
        self::assertArrayHasKey('2026-08-03', $occupied);
        // Departure day: not an occupied night, so the next guest may arrive.
        self::assertArrayNotHasKey('2026-08-04', $occupied);
    }

    public function testOccupiedNightsForRoomIncludesBlocksAndIgnoresOtherRooms(): void
    {
        $room = self::makeRoom(1);
        $room->setObject(new Subsidiary());
        $otherRoom = self::makeRoom(2);
        $otherRoom->setObject(new Subsidiary());

        $reservationRepo = $this->createStub(ReservationRepository::class);
        $reservationRepo->method('loadBlockingSpansForPeriod')->willReturn([
            ['appartmentId' => 2, 'startDate' => '2026-08-01', 'endDate' => '2026-08-05'],
        ]);

        $blockRepo = $this->createStub(RoomBlockRepository::class);
        $blockRepo->method('findForPeriod')->willReturn([
            self::makeBlock($room, '2026-08-03', '2026-08-04'),
            self::makeBlock($otherRoom, '2026-08-01', '2026-08-05'),
        ]);

        $service = new AvailabilityService($reservationRepo, $blockRepo, $this->createStub(AppartmentRepository::class));

        $occupied = $service->getOccupiedNightsForRoom($room, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-06'));

        self::assertSame(['2026-08-03'], array_keys($occupied));
    }

    /**
     * @param Reservation[] $overlappingReservations
     * @param RoomBlock[]   $overlappingBlocks
     */
    private function makeService(array $overlappingReservations, array $overlappingBlocks): AvailabilityService
    {
        $reservationRepo = $this->createStub(ReservationRepository::class);
        $reservationRepo->method('loadReservationsForApartmentWithoutStartEnd')->willReturn($overlappingReservations);

        $blockRepo = $this->createStub(RoomBlockRepository::class);
        $blockRepo->method('findOverlappingForApartment')->willReturn($overlappingBlocks);

        return new AvailabilityService($reservationRepo, $blockRepo, $this->createStub(AppartmentRepository::class));
    }

    public function testPreloadedOccupancyTreatsRoomWithoutReservationsAsFree(): void
    {
        $service = $this->makeService([], []);
        $room = self::makeRoom(1);

        self::assertTrue($service->isRoomAvailableFromPreloadedOccupancy($room, []));
        self::assertTrue($service->isRoomAvailableFromPreloadedOccupancy($room, [1 => ['reservationCount' => 0, 'persons' => 0]]));
    }

    public function testPreloadedOccupancyBlocksSingleOccupancyRoomOnAnyReservation(): void
    {
        $service = $this->makeService([], []);
        $room = self::makeRoom(1);

        self::assertFalse($service->isRoomAvailableFromPreloadedOccupancy(
            $room,
            [1 => ['reservationCount' => 1, 'persons' => 1]],
        ));
    }

    public function testPreloadedOccupancyLetsMultipleOccupancyRoomKeepFreeBeds(): void
    {
        $service = $this->makeService([], []);
        $room = self::makeRoom(1)->setMultipleOccupancy(true);

        self::assertTrue($service->isRoomAvailableFromPreloadedOccupancy(
            $room,
            [1 => ['reservationCount' => 1, 'persons' => 1]],
        ));
        self::assertFalse($service->isRoomAvailableFromPreloadedOccupancy(
            $room,
            [1 => ['reservationCount' => 1, 'persons' => 2]],
        ));
    }

    /**
     * The two predicates answer different questions and must keep doing so.
     *
     * `isRoomAvailable()` is asked with the party size and rejects a shared room
     * that could not fit them; the preloaded variant is evaluated while the room
     * list is being built, before any party size is known, and only asks whether a
     * bed is left at all. Collapsing the two would silently change what the public
     * search lists.
     */
    public function testPreloadedPredicateIgnoresRequestedPartySizeUnlikeFullCheck(): void
    {
        $room = self::makeRoom(1)->setMultipleOccupancy(true);
        $reservation = self::makeReservation(1, '2026-08-01', '2026-08-05');
        $reservation->setPersons(1);
        $service = $this->makeService([$reservation], []);

        // One bed left, two more guests requested: the full check says no …
        self::assertFalse($service->isRoomAvailable(
            $room,
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-05'),
            2,
        ));
        // … while the room still qualifies for the public list.
        self::assertTrue($service->isRoomAvailableFromPreloadedOccupancy(
            $room,
            [1 => ['reservationCount' => 1, 'persons' => 1]],
        ));
    }

    private static function makeRoom(int $id): Appartment
    {
        $room = new Appartment();
        $room->setId($id);
        $room->setNumber((string) $id);
        $room->setBedsMax(2);

        return $room;
    }

    private static function makeReservation(int $id, string $start, string $end): Reservation
    {
        $reservation = new Reservation();
        $reservation->setId($id);
        $reservation->setStartDate(new \DateTime($start));
        $reservation->setEndDate(new \DateTime($end));
        $reservation->setPersons(2);

        return $reservation;
    }

    private static function makeBlock(Appartment $room, string $start, string $end): RoomBlock
    {
        $block = new RoomBlock();
        $block->setAppartment($room)
            ->setStartDate(new \DateTimeImmutable($start))
            ->setEndDate(new \DateTimeImmutable($end))
            ->setReason('Renovierung');

        return $block;
    }
}
