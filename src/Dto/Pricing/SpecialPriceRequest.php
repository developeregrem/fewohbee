<?php

declare(strict_types=1);

namespace App\Dto\Pricing;

/**
 * A request to let an apartment price apply in a special period, independent of the web session.
 *
 * Without $amount the period is added to the price row $priceId itself, which must already be a
 * row with special periods. With $amount a new row is created as a copy of $priceId with that
 * amount and only this period.
 */
final readonly class SpecialPriceRequest
{
    public function __construct(
        public int $priceId,
        public \DateTimeImmutable $firstNight,
        public \DateTimeImmutable $lastNight,
        public string $periodDescription,
        public ?float $amount = null,
        public ?string $rowDescription = null,
        public bool $overwriteConflicts = false,
    ) {
    }

    public function createsRow(): bool
    {
        return null !== $this->amount;
    }

    /**
     * Stable hash over every field, so a confirmation can prove it refers to exactly this request.
     */
    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode([
            'priceId' => $this->priceId,
            'firstNight' => $this->firstNight->format('Y-m-d'),
            'lastNight' => $this->lastNight->format('Y-m-d'),
            'periodDescription' => $this->periodDescription,
            'amount' => null !== $this->amount ? round($this->amount, 2) : null,
            'rowDescription' => $this->rowDescription,
            'overwriteConflicts' => $this->overwriteConflicts,
        ]));
    }
}
