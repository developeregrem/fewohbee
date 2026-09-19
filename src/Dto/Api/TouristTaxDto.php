<?php

declare(strict_types=1);

namespace App\Dto\Api;

use App\Entity\TouristTax;

/**
 * API representation of a configured tourist tax (Kurtaxe / city tax).
 *
 * Like {@see PriceDto} this is configuration, not an amount: `PER_NIGHT_FLAT` taxes
 * carry a tariff per guest category, while `PERCENT_PER_ROOM` taxes are a percentage
 * of the apartment total and therefore have no per-night figure at all. What a concrete
 * stay owes comes from the quote endpoint, what a month owes from the report endpoint.
 */
final readonly class TouristTaxDto
{
    /**
     * @param list<array{id: int, name: string|null}>  $subsidiaries
     * @param list<array<string, mixed>>               $rates
     * @param array{id: int, name: string, rate: float}|null $taxRate
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $active,
        public string $calculationMode,
        public ?float $percentageRate,
        public ?string $percentageBase,
        public bool $appliesOnlyToAdult,
        public bool $includesVat,
        public ?string $validFrom,
        public ?string $validTo,
        public int $sortOrder,
        public array $subsidiaries,
        public ?array $taxRate,
        public array $rates,
    ) {
    }

    public static function fromEntity(TouristTax $tax): self
    {
        $subsidiaries = [];
        foreach ($tax->getSubsidiaries() as $subsidiary) {
            $subsidiaries[] = ['id' => (int) $subsidiary->getId(), 'name' => $subsidiary->getName()];
        }

        $rates = [];
        foreach ($tax->getRates() as $rate) {
            $category = $rate->getGuestCategory();
            $rates[] = [
                'guestCategory' => [
                    'id' => $category?->getId(),
                    'name' => $category?->getName(),
                ],
                'pricePerNight' => $rate->getPricePerNightFloat(),
                'reportGroup' => $rate->getReportGroup(),
            ];
        }

        $taxRate = $tax->getTaxRate();

        return new self(
            id: (int) $tax->getId(),
            name: $tax->getName(),
            active: $tax->isActive(),
            calculationMode: $tax->getCalculationMode()->value,
            percentageRate: $tax->getPercentageRateFloat(),
            percentageBase: $tax->getPercentageBase()?->value,
            appliesOnlyToAdult: $tax->isAppliesOnlyToAdult(),
            includesVat: $tax->isIncludesVat(),
            validFrom: $tax->getValidFrom()?->format('Y-m-d'),
            validTo: $tax->getValidTo()?->format('Y-m-d'),
            sortOrder: $tax->getSortOrder(),
            subsidiaries: $subsidiaries,
            taxRate: null !== $taxRate
                ? ['id' => (int) $taxRate->getId(), 'name' => $taxRate->getName(), 'rate' => $taxRate->getRateFloat()]
                : null,
            rates: $rates,
        );
    }
}
