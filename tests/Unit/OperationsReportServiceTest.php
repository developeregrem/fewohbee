<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\Enum\RoomCategoryKind;
use App\Entity\Reservation;
use App\Entity\RoomCategory;
use App\Repository\AppartmentRepository;
use App\Repository\ReservationRepository;
use App\Repository\RoomBlockRepository;
use App\Repository\RoomDayStatusRepository;
use App\Service\HousekeepingViewService;
use App\Service\MonthlyStatsService;
use App\Service\OperationsReportService;
use App\Service\ReservationNameResolver;
use App\Service\TouristTaxReportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class OperationsReportServiceTest extends TestCase
{
    /**
     * @param list<string> $occupancyTypes
     * @param list<array{room: string, guest: string}> $expectedRows
     */
    #[DataProvider('mealsChecklistFilters')]
    public function testMealsChecklistKeepsVisibleRoomsAndGuestNames(array $occupancyTypes, array $expectedRows): void
    {
        $firstRoom = $this->makeRoom(1, '2');
        $secondRoom = $this->makeRoom(2, '10');
        $emptyRoom = $this->makeRoom(3, '11');
        $alice = $this->makeCustomer('Alice', 'Lake');
        $bob = $this->makeCustomer('Bob', 'Shore');
        $casey = $this->makeCustomer('Casey', 'Reed');
        $firstStay = $this->makeReservation($firstRoom, '2026-05-01', '2026-05-04');
        $firstStay->addCustomer($alice)->addCustomer($bob);
        $secondStay = $this->makeReservation($firstRoom, '2026-05-02', '2026-05-05');
        $secondStay->addCustomer($this->makeCustomer('Alice', 'Lake'));
        $bookerOnlyStay = $this->makeReservation($secondRoom, '2026-05-02', '2026-05-03');
        $bookerOnlyStay->setBooker($casey);
        $service = $this->makeService(
            [$firstRoom, $secondRoom, $emptyRoom],
            [$firstStay, $secondStay, $bookerOnlyStay],
        );

        $data = $service->buildReportData(
            new \DateTimeImmutable('2026-05-01'),
            new \DateTimeImmutable('2026-05-02'),
            null,
            $occupancyTypes,
        );

        $expected = array_map(static fn (array $row): array => $row + ['cells' => ['', '']], $expectedRows);
        self::assertSame($expected, $data['simple']['views']['mealsChecklist']['rows']);
        self::assertSame([$firstRoom, $secondRoom, $emptyRoom], $data['rangeView']['apartments']);
        self::assertSame([$firstStay, $secondStay, $bookerOnlyStay], $data['reservations']);
        self::assertSame(['2026-05-01', '2026-05-02'], array_column($data['simple']['days'], 'dateKey'));
    }

    /** @return iterable<string, array{list<string>, list<array{room: string, guest: string}>}> */
    public static function mealsChecklistFilters(): iterable
    {
        $firstRoomGuests = [
            ['room' => '2', 'guest' => 'Lake, Alice'],
            ['room' => '2', 'guest' => 'Shore, Bob'],
        ];
        $bookerFallback = ['room' => '10', 'guest' => 'Reed, Casey'];
        $emptyRoom = ['room' => '11', 'guest' => '-'];

        yield 'all rooms' => [
            ['FREE', 'STAYOVER', 'ARRIVAL', 'DEPARTURE', 'TURNOVER', 'BLOCKED'],
            [...$firstRoomGuests, $bookerFallback, $emptyRoom],
        ];
        yield 'arrivals on either day' => [['ARRIVAL'], [...$firstRoomGuests, $bookerFallback]];
        yield 'free on at least one day' => [['FREE'], [$bookerFallback, $emptyRoom]];
        yield 'no matching rooms' => [['BLOCKED'], []];
    }

    public function testEmptyPropertyKeepsTheChecklistAndReservationListEmpty(): void
    {
        $data = $this->makeService([], [])->buildReportData(
            new \DateTimeImmutable('2026-05-01'),
            new \DateTimeImmutable('2026-05-02'),
            null,
            ['FREE'],
        );

        self::assertSame([], $data['simple']['views']['mealsChecklist']['rows']);
        self::assertSame([], $data['reservations']);
        self::assertCount(2, $data['simple']['days']);
    }

    public function testEventEndsOnItsLastDay(): void
    {
        $hall = $this->makeRoom(1, 'Saal');
        $hall->setRoomCategory((new RoomCategory())->setKind(RoomCategoryKind::EVENT_SPACE));
        // Friday to Saturday, stored up to Sunday.
        $service = $this->makeService([$hall], [$this->makeReservation($hall, '2026-05-01', '2026-05-03')]);
        $types = ['FREE', 'STAYOVER', 'ARRIVAL', 'DEPARTURE', 'TURNOVER', 'BLOCKED'];

        $lastDay = new \DateTimeImmutable('2026-05-02');
        $data = $service->buildReportData($lastDay, $lastDay, null, $types);
        self::assertSame('operations.frontdesk.departures', $data['simple']['reservations'][0]['frontdeskSectionKey']);
        self::assertSame('01.05.2026 - 02.05.2026', $data['simple']['reservations'][0]['period']);
        self::assertSame('DEPARTURE', $data['dayViews']['2026-05-02']['rows'][0]['occupancyType']);

        $dayAfter = new \DateTimeImmutable('2026-05-03');
        $data = $service->buildReportData($dayAfter, $dayAfter, null, $types);
        self::assertNull($data['simple']['reservations'][0]['frontdeskSectionKey']);
        self::assertSame('FREE', $data['dayViews']['2026-05-03']['rows'][0]['occupancyType']);
        self::assertSame([], $data['dayViews']['2026-05-03']['rows'][0]['apartmentReservations']);
    }

    /**
     * @param list<Appartment> $rooms
     * @param list<Reservation> $reservations
     */
    private function makeService(array $rooms, array $reservations): OperationsReportService
    {
        $apartments = $this->createStub(AppartmentRepository::class);
        $apartments->method('findAll')->willReturn($rooms);
        $reservationRepository = $this->createStub(ReservationRepository::class);
        $reservationRepository->method('findForHousekeepingRange')->willReturn($reservations);
        $statuses = $this->createStub(RoomDayStatusRepository::class);
        $statuses->method('findForApartmentsAndDates')->willReturn([]);
        $blocks = $this->createStub(RoomBlockRepository::class);
        $blocks->method('findForApartments')->willReturn([]);
        $viewService = new HousekeepingViewService(
            $apartments,
            $reservationRepository,
            $statuses,
            $this->createStub(TranslatorInterface::class),
            $blocks,
            new ReservationNameResolver(),
        );

        return new OperationsReportService(
            $viewService,
            $this->createStub(MonthlyStatsService::class),
            $this->createStub(TouristTaxReportService::class),
        );
    }

    private function makeRoom(int $id, string $number): Appartment
    {
        $room = new Appartment();
        $room->setId($id);
        $room->setNumber($number);
        $room->setDescription('Room '.$number);
        $room->setBedsMax(2);

        return $room;
    }

    private function makeReservation(Appartment $room, string $start, string $end): Reservation
    {
        $reservation = new Reservation();
        $reservation->setAppartment($room);
        $reservation->setStartDate(new \DateTime($start));
        $reservation->setEndDate(new \DateTime($end));
        $reservation->setPersons(2);

        return $reservation;
    }

    private function makeCustomer(string $firstname, string $lastname): Customer
    {
        $customer = new Customer();
        $customer->setFirstname($firstname);
        $customer->setLastname($lastname);

        return $customer;
    }
}
