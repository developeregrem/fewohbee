<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\AccountingAccount;
use App\Entity\Enum\TaxCalculationMode;
use App\Entity\TaxRate;

/**
 * Per-night, per-category result of TouristTaxService::calculateForReservation.
 *
 * For PER_NIGHT_FLAT (Kurtaxe): total = pricePerNight × nights × count.
 * For PERCENT_PER_ROOM (city tax): percentageRate is applied to apartmentBase,
 * and the final amount is stored in precomputedTotal so the total() method does
 * not have to recompute it.
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
        public readonly TaxCalculationMode $calculationMode = TaxCalculationMode::PER_NIGHT_FLAT,
        public readonly ?float $percentageRate = null,
        public readonly ?float $apartmentBase = null,
        public readonly ?float $precomputedTotal = null,
    ) {
    }

    public function total(): float
    {
        if (null !== $this->precomputedTotal) {
            return $this->precomputedTotal;
        }

        return $this->pricePerNight * $this->nights * $this->count;
    }
}
