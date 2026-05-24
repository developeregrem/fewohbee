<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Price;

/**
 * Structured per-night decomposition of an apartment price across guest categories.
 *
 * The base apartment Price is treated as the per-head rate for the ADULT bucket;
 * for every non-ADULT category present in Reservation.guestCounts an optional
 * GuestCategoryModifier adjusts that per-head price.
 */
final class PriceBreakdown
{
    /** @var PriceBreakdownLine[] */
    public array $lines = [];

    public function __construct(
        public readonly \DateTimeInterface $night,
        public readonly ?Price $basePrice,
    ) {
    }

    public function addLine(PriceBreakdownLine $line): void
    {
        $this->lines[] = $line;
    }

    public function total(): float
    {
        $sum = 0.0;
        foreach ($this->lines as $line) {
            $sum += $line->total();
        }

        return $sum;
    }
}
