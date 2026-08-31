<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync;

use App\Entity\Appartment;
use App\Entity\CalendarSync;
use App\Entity\Reservation;
use App\Entity\RoomBlock;
use App\Repository\RoomBlockRepository;
use App\Service\DisplayNameResolver;
use Doctrine\ORM\EntityManagerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Node;

/**
 * Exports an apartment's blocking reservations and room blocks as an RFC 5545 calendar.
 */
final class ApartmentCalendarExportService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RoomBlockRepository $roomBlockRepository,
        private readonly DisplayNameResolver $displayNameResolver,
    ) {
    }

    /**
     * Build the apartment feed and record the successful export timestamp.
     *
     * The timestamp is updated only after Sabre has serialized the complete calendar.
     */
    public function export(CalendarSync $sync): string
    {
        $apartment = $sync->getApartment();
        if (!$apartment instanceof Appartment) {
            throw new \LogicException('A calendar sync must belong to an apartment.');
        }

        $calendar = new VCalendar([
            'PRODID' => '-//FewohBee//Apartment Calendar//EN',
            'METHOD' => 'PUBLISH',
            'X-WR-CALNAME' => 'Bookings Apartment '.$apartment->getNumber(),
            'X-WR-TIMEZONE' => date_default_timezone_get(),
        ]);

        foreach ($apartment->getReservations() as $reservation) {
            if ($reservation->isConflict() || $reservation->isConflictIgnored()) {
                continue;
            }
            if ($sync->getReservationStatus()->contains($reservation->getReservationStatus())) {
                $this->addReservationEvent($calendar, $reservation, $sync);
            }
        }

        // A room block always makes the physical room unavailable, independent of status filters.
        foreach ($this->roomBlockRepository->findBy(['appartment' => $apartment]) as $block) {
            $this->addRoomBlockEvent($calendar, $block);
        }

        $content = $calendar->serialize();
        $sync->setLastExport(new \DateTime());
        $this->entityManager->flush();

        return $content;
    }

    /**
     * Add a booking as an opaque, all-day interval whose DTEND is exclusive.
     *
     * @param VCalendar<Node> $calendar
     */
    private function addReservationEvent(VCalendar $calendar, Reservation $reservation, CalendarSync $sync): void
    {
        $createdAt = \DateTimeImmutable::createFromMutable($reservation->getReservationDate())
            ->setTimezone(new \DateTimeZone('UTC'));

        $event = $calendar->add('VEVENT', [
            'UID' => $reservation->getUuid()->toBase32().'@fewohbee',
            'DTSTAMP' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'CREATED' => $createdAt,
            'LAST-MODIFIED' => $createdAt,
            'DESCRIPTION' => '',
            'LOCATION' => '',
            'SEQUENCE' => 0,
            'STATUS' => 'CONFIRMED',
            'SUMMARY' => $this->buildReservationSummary($reservation, $sync),
            'TRANSP' => 'OPAQUE',
        ]);
        if (!$event instanceof VEvent) {
            throw new \LogicException('Sabre did not create a VEVENT component.');
        }

        $event->add('DTSTART', $reservation->getStartDate()->format('Ymd'), ['VALUE' => 'DATE']);
        $event->add('DTEND', $reservation->getEndDate()->format('Ymd'), ['VALUE' => 'DATE']);
    }

    /**
     * Add a room block without exposing its internal reason or note.
     *
     * @param VCalendar<Node> $calendar
     */
    private function addRoomBlockEvent(VCalendar $calendar, RoomBlock $block): void
    {
        $createdAt = $block->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'));

        $event = $calendar->add('VEVENT', [
            'UID' => $block->getUuid()->toBase32().'@fewohbee',
            'DTSTAMP' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'CREATED' => $createdAt,
            'LAST-MODIFIED' => $createdAt,
            'DESCRIPTION' => '',
            'LOCATION' => '',
            'SEQUENCE' => 0,
            'STATUS' => 'CONFIRMED',
            'SUMMARY' => 'Blocked',
            'TRANSP' => 'OPAQUE',
        ]);
        if (!$event instanceof VEvent) {
            throw new \LogicException('Sabre did not create a VEVENT component.');
        }

        $event->add('DTSTART', $block->getStartDate()->format('Ymd'), ['VALUE' => 'DATE']);
        $event->add('DTEND', $block->getEndDate()->format('Ymd'), ['VALUE' => 'DATE']);
    }

    /** Build a privacy-aware booking summary, falling back safely when no booker exists. */
    private function buildReservationSummary(Reservation $reservation, CalendarSync $sync): string
    {
        $status = $reservation->getReservationStatus();
        $statusLabel = null !== $status ? $this->displayNameResolver->resolve($status) : '';

        if (!$sync->getExportGuestName() || null === $reservation->getBooker()) {
            return $statusLabel;
        }

        $booker = $reservation->getBooker();
        $guestName = trim(implode(' ', array_filter([
            $booker->getSalutation(),
            $booker->getFirstname(),
            $booker->getLastname(),
        ], static fn (mixed $part): bool => null !== $part && '' !== trim((string) $part))));

        return '' !== $guestName ? $guestName.' ('.$statusLabel.')' : $statusLabel;
    }
}
