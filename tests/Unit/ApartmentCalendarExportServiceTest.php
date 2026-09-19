<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Repository\RoomBlockRepository;
use App\Service\Calendar\Sync\ApartmentCalendarExportService;
use App\Service\DisplayNameResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Verify that apartment exports are valid, opaque RFC 5545 calendar events. */
final class ApartmentCalendarExportServiceTest extends TestCase
{
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
        $event = $calendar->VEVENT;

        self::assertSame('Booked, confirmed', (string) $event->SUMMARY);
        self::assertSame('OPAQUE', (string) $event->TRANSP);
        self::assertSame('', (string) $event->DESCRIPTION);
        self::assertSame('20300110', (string) $event->DTSTART);
        self::assertSame('20300115', (string) $event->DTEND);
        self::assertStringContainsString('SUMMARY:Booked\\, confirmed', $content);
        self::assertStringNotContainsString('DESCRIPION', $content);
        self::assertNotNull($apartment->getCalendarSync()->getLastExport());
    }
}
