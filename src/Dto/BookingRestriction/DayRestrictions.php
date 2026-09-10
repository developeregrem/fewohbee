<?php

declare(strict_types=1);

namespace App\Dto\BookingRestriction;

use App\Entity\BookingRestrictionRule;

/**
 * The resolved restrictions of a single date, together with the rules that caused them.
 * The channel export deliberately contains only neutral scalars — no rule or guest data.
 */
final readonly class DayRestrictions
{
    public function __construct(
        public \DateTimeImmutable $date,
        public int $minStayArrival,
        public int $minStayThrough,
        public bool $closedToArrival,
        public bool $closedToDeparture,
        public ?BookingRestrictionRule $arrivalRule = null,
        public ?BookingRestrictionRule $throughRule = null,
        public ?BookingRestrictionRule $closedToArrivalRule = null,
        public ?BookingRestrictionRule $closedToDepartureRule = null,
    ) {
    }

    /**
     * Every field is sent explicitly: an omitted value would leave a stale restriction
     * standing in the portal, so "no restriction" travels as 1 / false.
     *
     * @return array{date: string, min_stay_arrival: int, min_stay_through: int, closed_to_arrival: bool, closed_to_departure: bool}
     */
    public function toChannelValues(): array
    {
        return [
            'date' => $this->date->format('Y-m-d'),
            'min_stay_arrival' => $this->minStayArrival,
            'min_stay_through' => $this->minStayThrough,
            'closed_to_arrival' => $this->closedToArrival,
            'closed_to_departure' => $this->closedToDeparture,
        ];
    }
}
