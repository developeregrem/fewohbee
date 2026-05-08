<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\AccountingAccount;
use App\Entity\TaxRate;

/**
 * Per-night, per-category result of TouristTaxService::calculateForReservation.
 */
final class TouristTaxBreakdown
{
    public function __construct(
        public readonly int $taxId,
        public readonly string $taxName,
        public readonly int $categoryId,
        public readonly string $categoryName,
        public readonly float $pricePerNight,
        public readonly int $nights,
        public readonly int $count,
        public readonly ?string $reportGroup,
        public readonly ?TaxRate $taxRate,
        public readonly ?AccountingAccount $revenueAccount,
        public readonly bool $includesVat,
    ) {
    }

    public function total(): float
    {
        return $this->pricePerNight * $this->nights * $this->count;
    }
}
