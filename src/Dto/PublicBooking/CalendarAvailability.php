<?php

declare(strict_types=1);

namespace App\Dto\PublicBooking;

/**
 * Per-night availability of one room, as served to anonymous visitors.
 *
 * Deliberately reduced to night booleans and a pagination flag: the public surface
 * must not reveal how many rooms exist, why a night is taken, or anything about the
 * guests occupying it.
 */
final readonly class CalendarAvailability
{
    /**
     * @param array<string, bool> $nights Y-m-d => true when the night is bookable
     * @param bool                $hasMore true while later nights remain inside the booking horizon
     */
    public function __construct(
        public string $roomUuid,
        public string $from,
        public string $toExclusive,
        public array $nights,
        public bool $hasMore,
    ) {
    }

    /**
     * Wire format for the calendar endpoint. Values are 1/0 to keep the payload small.
     *
     * @return array{room: string, from: string, toExclusive: string, nights: array<string, int>, hasMore: bool}
     */
    public function toArray(): array
    {
        return [
            'room' => $this->roomUuid,
            'from' => $this->from,
            'toExclusive' => $this->toExclusive,
            'nights' => array_map(static fn (bool $free): int => $free ? 1 : 0, $this->nights),
            'hasMore' => $this->hasMore,
        ];
    }
}
