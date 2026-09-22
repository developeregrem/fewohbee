<?php

declare(strict_types=1);

namespace App\Dto\Reservation;

/**
 * A request to book one room, independent of the web session (MCP today, channel managers later).
 * Either $customerId or $booker identifies the booker.
 */
final readonly class ReservationBookingRequest
{
    /**
     * @param array<int, int> $guestCounts   guest category id => head count
     * @param list<int>|null  $extraPriceIds misc price ids to book; null = the extras staff get preselected
     */
    public function __construct(
        public int $apartmentId,
        public \DateTimeImmutable $arrival,
        public \DateTimeImmutable $departure,
        public int $persons,
        public array $guestCounts,
        public int $statusId,
        public int $originId,
        public ?int $customerId = null,
        public ?BookerData $booker = null,
        public ?string $remark = null,
        public ?array $extraPriceIds = null,
    ) {
    }

    /**
     * Stable hash over every field, so a confirmation can prove it refers to exactly this request.
     */
    public function fingerprint(): string
    {
        $guestCounts = $this->guestCounts;
        ksort($guestCounts);
        $extras = $this->extraPriceIds;
        if (null !== $extras) {
            sort($extras);
        }

        return hash('sha256', (string) json_encode([
            'apartmentId' => $this->apartmentId,
            'arrival' => $this->arrival->format('Y-m-d'),
            'departure' => $this->departure->format('Y-m-d'),
            'persons' => $this->persons,
            'guestCounts' => $guestCounts,
            'statusId' => $this->statusId,
            'originId' => $this->originId,
            'customerId' => $this->customerId,
            'booker' => $this->booker?->toArray(),
            'remark' => $this->remark,
            'extras' => $extras,
        ], \JSON_THROW_ON_ERROR));
    }
}
