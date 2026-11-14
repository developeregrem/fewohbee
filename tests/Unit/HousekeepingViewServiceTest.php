<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Enum\RoomCategoryKind;
use App\Entity\Reservation;
use App\Entity\RoomCategory;
use App\Repository\AppartmentRepository;
use App\Repository\ReservationRepository;
use App\Repository\RoomBlockRepository;
use App\Repository\RoomDayStatusRepository;
use App\Service\HousekeepingViewService;
use App\Service\ReservationNameResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class HousekeepingViewServiceTest extends TestCase
{
    /**
     * @return array<string, array{0: \DateTimeImmutable, 1: Reservation[], 2: string}>
     */
    public static function occupancyCases(): array
    {
        $date = new \DateTimeImmutable('2024-01-05');

        return [
            'free' => [$date, [], 'FREE'],
            'stayover' => [
                $date,
                [self::makeReservation('2024-01-01', '2024-01-07')],
                'STAYOVER',
            ],
            'arrival' => [
                $date,
                [self::makeReservation('2024-01-05', '2024-01-10')],
                'ARRIVAL',
            ],
            'departure' => [
                $date,
                [self::makeReservation('2024-01-01', '2024-01-05')],
                'DEPARTURE',
            ],
            'turnover' => [
                $date,
                [
                    self::makeReservation('2024-01-01', '2024-01-05'),
                    self::makeReservation('2024-01-05', '2024-01-10'),
                ],
                'TURNOVER',
            ],
            'overlap conflict' => [
                $date,
                [
                    self::makeReservation('2024-01-02', '2024-01-06'),
                    self::makeReservation('2024-01-03', '2024-01-07'),
                ],
                'STAYOVER',
            ],
        ];
    }

    /** @param list<Reservation> $reservations */
    #[DataProvider('occupancyCases')]
    public function testResolveOccupancyForDay(\DateTimeImmutable $date, array $reservations, string $expected): void
    {
        $service = new HousekeepingViewService(
            $this->createStub(AppartmentRepository::class),
            $this->createStub(ReservationRepository::class),
            $this->createStub(RoomDayStatusRepository::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(RoomBlockRepository::class),
            new ReservationNameResolver()
        );

        $result = $service->resolveOccupancyForDay($date, $reservations);

        self::assertSame($expected, $result['type']);
    }

    public function testBlockedNightResolvesToBlocked(): void
    {
        $service = $this->makeService();
        $block = self::makeBlock('2024-01-04', '2024-01-06');

        $result = $service->resolveOccupancyForDay(new \DateTimeImmutable('2024-01-05'), [], [$block]);

        self::assertSame('BLOCKED', $result['type']);
        self::assertSame('Renovierung', $result['summary']);
        self::assertNull($result['guestCount']);
    }

    public function testDepartureTakesPrecedenceOverBlockStartingSameDay(): void
    {
        $service = $this->makeService();
        $block = self::makeBlock('2024-01-05', '2024-01-08');
        $departure = self::makeReservation('2024-01-01', '2024-01-05');

        $result = $service->resolveOccupancyForDay(new \DateTimeImmutable('2024-01-05'), [$departure], [$block]);

        self::assertSame('DEPARTURE', $result['type']);
    }

    public function testBlockEndDayIsFreeAgain(): void
    {
        $service = $this->makeService();
        $block = self::makeBlock('2024-01-04', '2024-01-06');

        $result = $service->resolveOccupancyForDay(new \DateTimeImmutable('2024-01-06'), [], [$block]);

        self::assertSame('FREE', $result['type']);
    }

    /** @param list<Reservation> $reservations */
    #[DataProvider('eventSpaceCases')]
    public function testEventEndsOnItsLastDay(string $date, array $reservations, string $expected): void
    {
        $occupancy = $this->makeService()->resolveOccupancyForDay(new \DateTimeImmutable($date), $reservations);

        self::assertSame($expected, $occupancy['type']);
    }

    /** @return iterable<string, array{string, list<Reservation>, string}> */
    public static function eventSpaceCases(): iterable
    {
        // Friday to Sunday, stored up to Monday.
        $weekend = self::makeEvent('2024-01-05', '2024-01-08');
        yield 'first day' => ['2024-01-05', [$weekend], 'ARRIVAL'];
        yield 'day in between' => ['2024-01-06', [$weekend], 'STAYOVER'];
        yield 'last day' => ['2024-01-07', [$weekend], 'DEPARTURE'];
        yield 'day after' => ['2024-01-08', [$weekend], 'FREE'];

        // One Saturday, stored up to Sunday.
        $saturday = self::makeEvent('2024-01-06', '2024-01-07');
        yield 'one-day event comes and goes on its day' => ['2024-01-06', [$saturday], 'TURNOVER'];
        yield 'day after a one-day event' => ['2024-01-07', [$saturday], 'FREE'];
        yield 'another event starting the day after' => ['2024-01-07', [$saturday, self::makeEvent('2024-01-07', '2024-01-09')], 'ARRIVAL'];
    }

    private function makeService(): HousekeepingViewService
    {
        return new HousekeepingViewService(
            $this->createStub(AppartmentRepository::class),
            $this->createStub(ReservationRepository::class),
            $this->createStub(RoomDayStatusRepository::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(RoomBlockRepository::class),
            new ReservationNameResolver()
        );
    }

    private static function makeBlock(string $start, string $end): \App\Entity\RoomBlock
    {
        $block = new \App\Entity\RoomBlock();
        $block->setAppartment(new \App\Entity\Appartment())
            ->setStartDate(new \DateTimeImmutable($start))
            ->setEndDate(new \DateTimeImmutable($end))
            ->setReason('Renovierung');

        return $block;
    }

    private static function makeEvent(string $start, string $end): Reservation
    {
        $event = self::makeReservation($start, $end);
        $event->setAppartment((new Appartment())->setRoomCategory((new RoomCategory())->setKind(RoomCategoryKind::EVENT_SPACE)));

        return $event;
    }

    private static function makeReservation(string $start, string $end): Reservation
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime($start));
        $reservation->setEndDate(new \DateTime($end));
        $reservation->setPersons(2);

        return $reservation;
    }
}
