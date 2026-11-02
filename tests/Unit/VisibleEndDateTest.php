<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Enum\RoomCategoryKind;
use App\Entity\InvoiceAppartment;
use App\Entity\Reservation;
use App\Entity\RoomCategory;
use PHPUnit\Framework\TestCase;

/** What people read as the end: the departure day, for an event its last day. */
final class VisibleEndDateTest extends TestCase
{
    public function testAccommodationEndsOnTheDepartureDay(): void
    {
        $reservation = $this->reservation(RoomCategoryKind::ACCOMMODATION, '2030-01-12', '2030-01-14');

        self::assertSame('2030-01-14', $reservation->getVisibleEndDate()->format('Y-m-d'));
    }

    public function testEventEndsOnItsLastDay(): void
    {
        $reservation = $this->reservation(RoomCategoryKind::EVENT_SPACE, '2030-01-12', '2030-01-14');

        self::assertSame('2030-01-13', $reservation->getVisibleEndDate()->format('Y-m-d'));
        self::assertSame('2030-01-14', $reservation->getEndDate()->format('Y-m-d'));
    }

    public function testEventStoredOnOneDayEndsThatDay(): void
    {
        $reservation = $this->reservation(RoomCategoryKind::EVENT_SPACE, '2030-01-12', '2030-01-12');

        self::assertSame('2030-01-12', $reservation->getVisibleEndDate()->format('Y-m-d'));
    }

    public function testReservationWithoutRoomEndsOnTheDepartureDay(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2030-01-12'));
        $reservation->setEndDate(new \DateTime('2030-01-14'));

        self::assertSame('2030-01-14', $reservation->getVisibleEndDate()->format('Y-m-d'));
    }

    public function testInvoicePositionFollowsTheSameRule(): void
    {
        $position = new InvoiceAppartment();
        self::assertNull($position->getVisibleEndDate());

        $position->setStartDate(new \DateTime('2030-01-12'));
        $position->setEndDate(new \DateTime('2030-01-14'));
        self::assertSame('2030-01-14', $position->getVisibleEndDate()->format('Y-m-d'));

        $position->setEventSpace(true);
        self::assertSame('2030-01-13', $position->getVisibleEndDate()->format('Y-m-d'));
    }

    private function reservation(RoomCategoryKind $kind, string $start, string $end): Reservation
    {
        $reservation = new Reservation();
        $reservation->setAppartment((new Appartment())->setRoomCategory((new RoomCategory())->setKind($kind)));
        $reservation->setStartDate(new \DateTime($start));
        $reservation->setEndDate(new \DateTime($end));

        return $reservation;
    }
}
