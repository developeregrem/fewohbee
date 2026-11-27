<?php

declare(strict_types=1);

namespace App\Dto;

use App\Dto\Pricing\NightRate;
use App\Entity\Price;

/**
 * Structured per-night decomposition of an apartment price across guest categories.
 *
 * The base apartment Price is treated as the per-head rate for the ADULT bucket;
 * for every non-ADULT category present in Reservation.guestCounts an optional
 * GuestCategoryModifier adjusts that per-head price.
 *
 * $rate carries the unit price actually billed for the night, which a price
 * promise may hold apart from what $basePrice says today.
 */
final class PriceBreakdown
{
    /** @var PriceBreakdownLine[] */
    public array $lines = [];

    /**
     * The persons a per-room price bills through its surcharge, by guest category, each line at
     * the surcharge its guests pay. Null where they are not told apart - no guest counts, an event
     * space, a promise made before - and every further person pays the full surcharge.
     *
     * @var list<PriceBreakdownLine>|null
     */
    public ?array $extraPersonLines = null;

    /** Null exactly when $basePrice is null. */
    public readonly ?NightRate $rate;

    public function __construct(
        public readonly \DateTimeInterface $night,
        public readonly ?Price $basePrice,
        ?NightRate $rate = null,
    ) {
        $this->rate = $rate ?? (null !== $basePrice ? NightRate::live(\DateTimeImmutable::createFromInterface($night), $basePrice) : null);
    }

    public function addLine(PriceBreakdownLine $line): void
    {
        $this->lines[] = $line;
    }

    /** What the surcharge of a per-room price adds to this night for a party of $persons. */
    public function extraPersonTotal(int $persons): float
    {
        if (null === $this->rate || !$this->rate->isPerRoom || null === $this->rate->extraPersonSurcharge) {
            return 0.0;
        }
        if (null === $this->extraPersonLines) {
            return $this->rate->surchargeTotal($persons);
        }

        $sum = 0.0;
        foreach ($this->extraPersonLines as $line) {
            $sum += $line->total();
        }

        return $sum;
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
