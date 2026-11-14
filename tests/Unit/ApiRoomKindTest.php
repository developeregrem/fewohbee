<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dto\Api\InvoiceDto;
use App\Dto\Api\ReservationDto;
use App\Entity\Appartment;
use App\Entity\Enum\RoomCategoryKind;
use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\Reservation;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use PHPUnit\Framework\TestCase;

/**
 * An event space is booked per day but stored like a night: integrations need the kind to read
 * endDate as the day after the last day.
 */
final class ApiRoomKindTest extends TestCase
{
    public function testReservationNamesTheKindOfItsRoom(): void
    {
        self::assertSame('event_space', $this->reservationDto(RoomCategoryKind::EVENT_SPACE)->apartment['kind']);
        self::assertSame('accommodation', $this->reservationDto(RoomCategoryKind::ACCOMMODATION)->apartment['kind']);
    }

    public function testRoomWithoutCategoryIsAccommodation(): void
    {
        self::assertSame('accommodation', $this->reservationDto(null)->apartment['kind']);
    }

    public function testInvoiceLineTellsWhetherItWasAnEventSpace(): void
    {
        $invoice = new Invoice();
        $invoice->setNumber('1');
        $invoice->setDate(new \DateTime('2041-05-10'));
        $invoice->setStatus(1);
        foreach ([true, false] as $eventSpace) {
            $line = (new InvoiceAppartment())->setEventSpace($eventSpace);
            $line->setStartDate(new \DateTime('2041-05-04'));
            $line->setEndDate(new \DateTime('2041-05-05'));
            $line->setPrice('500');
            $line->setVat(19);
            $line->setIncludesVat(true);
            $line->setIsFlatPrice(false);
            $line->setIsPerRoom(true);
            $invoice->addAppartment($line);
        }

        $dto = InvoiceDto::fromEntity($invoice, ['gross' => 1000.0, 'net' => 1000.0, 'vat' => 0.0], [], []);

        self::assertSame([true, false], array_column($dto->apartments, 'eventSpace'));
        self::assertSame('2041-05-05', $dto->apartments[0]['endDate']);
        self::assertSame(1, $dto->apartments[0]['amount']);
    }

    private function reservationDto(?RoomCategoryKind $kind): ReservationDto
    {
        $room = new Appartment();
        $room->setObject(new Subsidiary());
        $room->setNumber('HALL');
        $room->setDescription('Hall');
        if (null !== $kind) {
            $room->setRoomCategory((new RoomCategory())->setKind($kind));
        }
        $reservation = new Reservation();
        $reservation->setAppartment($room);
        $reservation->setStartDate(new \DateTime('2041-05-04'));
        $reservation->setEndDate(new \DateTime('2041-05-05'));
        $reservation->setReservationDate(new \DateTime('2041-01-10'));
        $reservation->setPersons(40);

        return ReservationDto::fromEntity($reservation, [], 'Doe');
    }
}
