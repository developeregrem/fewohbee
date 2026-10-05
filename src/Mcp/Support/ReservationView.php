<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Entity\Customer;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Mcp\Security\McpDataFilter;
use App\Service\ReservationNameResolver;

/**
 * Renders a reservation for MCP output. Guest name and remark pass through McpDataFilter, so
 * without guests:read the booker only appears as a customer reference.
 */
final class ReservationView
{
    public function __construct(
        private readonly McpDataFilter $dataFilter,
        private readonly ReservationNameResolver $nameResolver,
    ) {
    }

    /**
     * @param list<string>                    $types      arrival/departure/inhouse relative to a queried range
     * @param list<array<string, mixed>>|null $invoices   null when the token may not read invoices
     * @param bool                            $withExtras include the booked extras (one query per reservation)
     *
     * @return array<string, mixed>
     */
    public function render(Reservation $reservation, array $types = [], ?array $invoices = null, bool $withExtras = false): array
    {
        $apartment = $reservation->getAppartment();
        $object = $apartment?->getObject();
        $status = $reservation->getReservationStatus();
        $origin = $reservation->getReservationOrigin();
        $booker = $reservation->getBooker();
        $start = \DateTimeImmutable::createFromInterface($reservation->getStartDate());
        $end = \DateTimeImmutable::createFromInterface($reservation->getEndDate());

        $view = [
            'id' => $reservation->getId(),
            'uuid' => $reservation->getUuid()?->toRfc4122(),
            'bookingGroupUuid' => $reservation->getBookingGroupUuid()?->toRfc4122(),
            'arrival' => $start->format('Y-m-d'),
            'departure' => $end->format('Y-m-d'),
            'nights' => (int) $start->diff($end)->days,
            'arrivalTime' => $reservation->getArrivalTime()?->format('H:i'),
            'departureTime' => $reservation->getDepartureTime()?->format('H:i'),
            'persons' => $reservation->getPersons(),
            'guestCounts' => $reservation->getGuestCounts(),
            'status' => ['id' => $status?->getId(), 'name' => $status?->getName()],
            'apartment' => ['id' => $apartment?->getId(), 'number' => $apartment?->getNumber(), 'description' => $apartment?->getDescription()],
            'object' => ['id' => $object?->getId(), 'name' => $object?->getName()],
            'origin' => ['id' => $origin?->getId(), 'name' => $origin?->getName()],
            'booker' => [
                'customerRef' => $booker instanceof Customer ? $booker->getId() : null,
                'name' => $this->dataFilter->personal($this->nameResolver->resolve($reservation)),
            ],
            'remark' => $this->dataFilter->untrustedText($reservation->getRemark()),
            'optionDate' => $reservation->getOptionDate()?->format('Y-m-d'),
            'reservationDate' => $reservation->getReservationDate()->format(\DateTimeInterface::ATOM),
            'isImported' => null !== $reservation->getCalendarSyncImport(),
        ];

        if ($withExtras) {
            $view['extras'] = array_values(array_map(
                static fn (Price $price): array => ['id' => $price->getId(), 'description' => $price->getDescription()],
                $reservation->getPrices()->toArray()
            ));
        }
        if ([] !== $types) {
            $view['types'] = $types;
        }
        if (null !== $invoices) {
            $view['invoices'] = $invoices;
        }

        return $view;
    }
}
