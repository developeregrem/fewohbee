<?php

declare(strict_types=1);

namespace App\Dto;

use App\Dto\Pricing\GuestAdjustment;
use App\Entity\GuestCategory;
use App\Entity\GuestCategoryModifier;

/**
 * $modifier is the live modifier entity, set only when the line was priced from the current
 * configuration. $adjustment describes the modifier in both cases - for a promised line it is the
 * copy stored with the promise - and is what invoices are built from.
 */
final class PriceBreakdownLine
{
    public readonly ?GuestAdjustment $adjustment;

    public function __construct(
        public readonly GuestCategory $category,
        public readonly int $count,
        public readonly float $unitPrice,
        public readonly ?GuestCategoryModifier $modifier = null,
        ?GuestAdjustment $adjustment = null,
    ) {
        $this->adjustment = $adjustment ?? (null !== $modifier ? GuestAdjustment::fromModifier($modifier) : null);
    }

    public function total(): float
    {
        return $this->count * $this->unitPrice;
    }
}
