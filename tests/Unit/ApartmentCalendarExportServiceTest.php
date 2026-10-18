<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Entity\RoomBlock;
use App\Repository\RoomBlockRepository;
use App\Service\Calendar\Sync\ApartmentCalendarExportService;
use App\Service\DisplayNameResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property\ICalendar\DateTime;
use Sabre\VObject\Reader;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Verify that apartment exports are valid, opaque RFC 5545 calendar events. */
final class ApartmentCalendarExportServiceTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    public function testReservationExportUsesValidPropertiesAndEscaping(): void
    {
        $apartment = new Appartment();
        $apartment->setNumber('A1');

        $status = (new ReservationStatus())->setName('Booked, confirmed');
        $apartment->getCalendarSync()->addReservationStatus($status);

        $reservation = new Reservation();
        $reservation->setAppartment($apartment);
        $reservation->setReservationStatus($status);
        $reservation->setReservationDate(new \DateTime('2029-12-01 10:00:00'));
        $reservation->setStartDate(new \DateTime('2030-01-10'));
        $reservation->setEndDate(new \DateTime('2030-01-15'));
        $reservation->setUuid(Uuid::v4());
        $apartment->addReservation($reservation);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $blockRepository = $this->createMock(RoomBlockRepository::class);
        $blockRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(['appartment' => $apartment])
            ->willReturn([]);

        $service = new ApartmentCalendarExportService(
            $entityManager,
            $blockRepository,
            new DisplayNameResolver($this->createStub(TranslatorInterface::class)),
        );

        $content = $service->export($apartment->getCalendarSync());
        $calendar = Reader::read($content);
        $event = $calendar->select('VEVENT')[0];
        self::assertInstanceOf(VEvent::class, $event);

        self::assertSame('Booked, confirmed', (string) $event->select('SUMMARY')[0]);
        self::assertSame('OPAQUE', (string) $event->select('TRANSP')[0]);
        self::assertSame('', (string) $event->select('DESCRIPTION')[0]);
        self::assertSame('20300110', (string) $event->select('DTSTART')[0]);
        self::assertSame('20300115', (string) $event->select('DTEND')[0]);
        self::assertStringContainsString('SUMMARY:Booked\\, confirmed', $content);
        self::assertStringNotContainsString('DESCRIPION', $content);
        self::assertNotNull($apartment->getCalendarSync()->getLastExport());
    }

    #[DataProvider('reservationTimeCases')]
    public function testReservationTimesAreExportedWhenEnabledWithMidnightForMissingTimes(
        ?bool $enabled,
        ?string $arrivalTime,
        ?string $departureTime,
        string $startDate,
        string $endDate,
        string $expectedStart,
        string $expectedEnd,
    ): void {
        $apartment = new Appartment();
        $apartment->setNumber('A1');
        $sync = $apartment->getCalendarSync();
        if (null !== $enabled) {
            $sync->setExportReservationTimes($enabled);
        }
        $status = (new ReservationStatus())->setName('Booked');
        $sync->addReservationStatus($status);
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime($startDate));
        $reservation->setEndDate(new \DateTime($endDate));
        $reservation->setArrivalTime(null !== $arrivalTime ? new \DateTime('2000-01-01 '.$arrivalTime) : null)
            ->setDepartureTime(null !== $departureTime ? new \DateTime('2000-01-01 '.$departureTime) : null)
            ->setReservationStatus($status)
            ->setUuid(Uuid::v4());
        $apartment->addReservation($reservation);
        $originalStart = clone $reservation->getStartDate();
        $originalEnd = clone $reservation->getEndDate();
        $originalArrival = null !== $reservation->getArrivalTime() ? clone $reservation->getArrivalTime() : null;
        $originalDeparture = null !== $reservation->getDepartureTime() ? clone $reservation->getDepartureTime() : null;

        $content = $this->export($apartment);
        $calendar = Reader::read($content);
        $event = $calendar->select('VEVENT')[0];
        self::assertInstanceOf(VEvent::class, $event);
        $start = $event->select('DTSTART')[0];
        $end = $event->select('DTEND')[0];

        self::assertSame($expectedStart, (string) $start);
        self::assertSame($expectedEnd, (string) $end);
        self::assertInstanceOf(DateTime::class, $start);
        self::assertInstanceOf(DateTime::class, $end);
        $expectedType = str_ends_with($expectedStart, 'Z') ? 'DATE-TIME' : 'DATE';
        self::assertSame($expectedType, $start->getValueType());
        self::assertSame($expectedType, $end->getValueType());
        self::assertEquals($originalStart, $reservation->getStartDate());
        self::assertEquals($originalEnd, $reservation->getEndDate());
        self::assertEquals($originalArrival, $reservation->getArrivalTime());
        self::assertEquals($originalDeparture, $reservation->getDepartureTime());
    }

    /** @return iterable<string, array{?bool, ?string, ?string, string, string, string, string}> */
    public static function reservationTimeCases(): iterable
    {
        yield 'disabled by default with both times' => [null, '15:30', '10:15', '2030-01-10', '2030-01-15', '20300110', '20300115'];
        yield 'explicitly disabled with both times' => [false, '15:30', '10:15', '2030-01-10', '2030-01-15', '20300110', '20300115'];
        yield 'disabled by default with only arrival time' => [null, '15:30', null, '2030-01-10', '2030-01-15', '20300110', '20300115'];
        yield 'explicitly disabled with only departure time' => [false, null, '10:15', '2030-01-10', '2030-01-15', '20300110', '20300115'];
        yield 'enabled in winter' => [true, '15:30', '10:15', '2030-01-10', '2030-01-15', '20300110T143000Z', '20300115T091500Z'];
        yield 'enabled in summer' => [true, '15:30', '10:15', '2030-07-10', '2030-07-15', '20300710T133000Z', '20300715T081500Z'];
        yield 'across spring daylight-saving change' => [true, '15:30', '10:15', '2030-03-30', '2030-04-01', '20300330T143000Z', '20300401T081500Z'];
        yield 'across autumn daylight-saving change' => [true, '15:30', '10:15', '2030-10-26', '2030-10-28', '20301026T133000Z', '20301028T091500Z'];
        yield 'missing arrival time defaults to midnight' => [true, null, '10:15', '2030-01-10', '2030-01-15', '20300109T230000Z', '20300115T091500Z'];
        yield 'missing departure time defaults to midnight' => [true, '15:30', null, '2030-01-10', '2030-01-15', '20300110T143000Z', '20300114T230000Z'];
        yield 'missing departure time across spring daylight-saving change' => [true, '15:30', null, '2030-03-30', '2030-04-01', '20300330T143000Z', '20300331T220000Z'];
        yield 'missing arrival time across autumn daylight-saving change' => [true, null, '10:15', '2030-10-26', '2030-10-28', '20301025T220000Z', '20301028T091500Z'];
        yield 'missing both times' => [true, null, null, '2030-01-10', '2030-01-15', '20300110', '20300115'];
        yield 'midnight is a recorded time' => [true, '00:00', '00:00', '2030-01-10', '2030-01-15', '20300109T230000Z', '20300114T230000Z'];
        yield 'only midnight arrival is recorded' => [true, '00:00', null, '2030-01-10', '2030-01-15', '20300109T230000Z', '20300114T230000Z'];
        yield 'only midnight departure is recorded' => [true, null, '00:00', '2030-01-10', '2030-01-15', '20300109T230000Z', '20300114T230000Z'];
    }

    public function testRoomBlocksRemainAllDayWhenReservationTimesAreEnabled(): void
    {
        $apartment = new Appartment();
        $apartment->setNumber('A1');
        $apartment->getCalendarSync()->setExportReservationTimes(true);
        $block = (new RoomBlock())
            ->setAppartment($apartment)
            ->setStartDate(new \DateTimeImmutable('2030-01-10'))
            ->setEndDate(new \DateTimeImmutable('2030-01-15'))
            ->setReason('Internal reason')
            ->setNote('Internal note');

        $content = $this->export($apartment, [$block]);
        $calendar = Reader::read($content);
        $event = $calendar->select('VEVENT')[0];
        self::assertInstanceOf(VEvent::class, $event);

        self::assertStringContainsString('DTSTART;VALUE=DATE:20300110', $content);
        self::assertStringContainsString('DTEND;VALUE=DATE:20300115', $content);
        self::assertSame('Blocked', (string) $event->select('SUMMARY')[0]);
        self::assertStringNotContainsString('Internal reason', $content);
        self::assertStringNotContainsString('Internal note', $content);
    }

    /** @param list<RoomBlock> $blocks */
    private function export(Appartment $apartment, array $blocks = []): string
    {
        $blockRepository = $this->createStub(RoomBlockRepository::class);
        $blockRepository->method('findBy')->willReturn($blocks);
        $service = new ApartmentCalendarExportService(
            $this->createStub(EntityManagerInterface::class),
            $blockRepository,
            new DisplayNameResolver($this->createStub(TranslatorInterface::class)),
        );

        return $service->export($apartment->getCalendarSync());
    }
}
