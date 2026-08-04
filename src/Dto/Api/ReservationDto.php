<?php

declare(strict_types=1);

namespace App\Dto\Api;

use App\Entity\Reservation;

/**
 * API representation of a reservation. Deliberately excludes guest PII beyond the
 * booker display name as well as invoices, prices and internal conflict flags.
 */
final readonly class ReservationDto
{
    /**
     * @param array<string, int>                        $guestCounts
     * @param array{id: int|null, name: string|null}    $status
     * @param array{id: int|null, number: string|null, description: string|null} $apartment
     * @param array{id: int|null, name: string|null}    $object
     * @param list<'arrival'|'departure'|'inhouse'>     $types
     */
    public function __construct(
        public ?string $uuid,
        public ?string $bookingGroupUuid,
        public string $startDate,
        public string $endDate,
        public ?string $arrivalTime,
        public ?string $departureTime,
        public int $persons,
        public array $guestCounts,
        public array $status,
        public array $apartment,
        public array $object,
        public ?string $origin,
        public string $bookerName,
        public ?string $remark,
        public ?string $optionDate,
        public string $reservationDate,
        public array $types,
        public bool $isImported,
    ) {
    }

    /**
     * @param list<'arrival'|'departure'|'inhouse'> $types
     */
    public static function fromEntity(Reservation $reservation, array $types, string $bookerName): self
    {
        $apartment = $reservation->getAppartment();
        $object = $apartment?->getObject();
        $status = $reservation->getReservationStatus();

        return new self(
            uuid: $reservation->getUuid()?->toRfc4122(),
            bookingGroupUuid: $reservation->getBookingGroupUuid()?->toRfc4122(),
            startDate: $reservation->getStartDate()->format('Y-m-d'),
            endDate: $reservation->getEndDate()->format('Y-m-d'),
            arrivalTime: $reservation->getArrivalTime()?->format('H:i'),
            departureTime: $reservation->getDepartureTime()?->format('H:i'),
            persons: $reservation->getPersons(),
            guestCounts: $reservation->getGuestCounts(),
            status: ['id' => $status?->getId(), 'name' => $status?->getName()],
            apartment: [
                'id' => $apartment?->getId(),
                'number' => $apartment?->getNumber(),
                'description' => $apartment?->getDescription(),
            ],
            object: ['id' => $object?->getId(), 'name' => $object?->getName()],
            origin: $reservation->getReservationOrigin()?->getName(),
            bookerName: $bookerName,
            remark: $reservation->getRemark(),
            optionDate: $reservation->getOptionDate()?->format('Y-m-d'),
            reservationDate: $reservation->getReservationDate()->format(\DateTimeInterface::ATOM),
            types: $types,
            isImported: null !== $reservation->getCalendarSyncImport(),
        );
    }
}
