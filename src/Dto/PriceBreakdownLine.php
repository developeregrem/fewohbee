<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\GuestCategory;
use App\Entity\GuestCategoryModifier;

final class PriceBreakdownLine
{
    public function __construct(
        public readonly GuestCategory $category,
        public readonly int $count,
        public readonly float $unitPrice,
        public readonly ?GuestCategoryModifier $modifier = null,
    ) {
    }

    public function total(): float
    {
        return $this->count * $this->unitPrice;
    }
}
