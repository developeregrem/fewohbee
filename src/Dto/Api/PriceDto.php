<?php

declare(strict_types=1);

namespace App\Dto\Api;

use App\Entity\Price;

/**
 * API representation of a configured price row — the catalogue, not a calculation.
 *
 * This is reference data: it says which price rows exist and when they apply. Working
 * out what a concrete stay costs from these fields means reimplementing period priority,
 * weekday matching, minStay selection and the guest-category modifiers, and it will drift
 * from the invoice. Use the quote endpoint for money; use this to render a price table.
 */
final readonly class PriceDto
{
    /**
     * @param array{id: int|null, name: string|null}|null $roomCategory
     * @param list<array{id: int, name: string|null}>     $origins
     * @param array<string, bool>                         $weekdays
     * @param list<array{startDate: string, endDate: string}> $periods
     * @param list<array<string, mixed>>                  $components
     */
    public function __construct(
        public int $id,
        public string $type,
        public ?string $description,
        public float $price,
        public float $vat,
        public bool $includesVat,
        public bool $isFlatPrice,
        public bool $isPerRoom,
        public ?int $numberOfPersons,
        public ?int $minStay,
        public bool $active,
        public ?array $roomCategory,
        public array $origins,
        public bool $allDays,
        public array $weekdays,
        public bool $allPeriods,
        public array $periods,
        public ?string $seasonStart,
        public ?string $seasonEnd,
        public bool $isBookableOnline,
        public bool $isMandatoryOnline,
        public bool $isDefaultActiveInReservationCreation,
        public bool $isPackage,
        public array $components,
    ) {
    }

    public static function fromEntity(Price $price): self
    {
        $category = $price->getRoomCategory();

        $origins = [];
        foreach ($price->getReservationOrigins() as $origin) {
            $origins[] = ['id' => (int) $origin->getId(), 'name' => $origin->getName()];
        }

        $periods = [];
        foreach ($price->getPricePeriods() as $period) {
            $periods[] = [
                'startDate' => $period->getStart()->format('Y-m-d'),
                'endDate' => $period->getEnd()->format('Y-m-d'),
            ];
        }
        usort($periods, static fn (array $a, array $b): int => strcmp($a['startDate'], $b['startDate']));

        $components = [];
        foreach ($price->getComponents() as $component) {
            $components[] = [
                'id' => $component->getId(),
                'description' => $component->getDescription(),
                'vat' => $component->getVat(),
                'allocationType' => $component->getAllocationType()->value,
                'allocationValue' => $component->isRemainder() ? null : $component->getAllocationValue(),
                'isRemainder' => $component->isRemainder(),
                'sortOrder' => $component->getSortOrder(),
            ];
        }
        usort($components, static fn (array $a, array $b): int => $a['sortOrder'] <=> $b['sortOrder']);

        return new self(
            id: (int) $price->getId(),
            type: 2 === (int) $price->getType() ? 'apartment' : 'misc',
            description: $price->getDescription(),
            price: round((float) $price->getPrice(), 2),
            vat: (float) $price->getVat(),
            includesVat: (bool) $price->getIncludesVat(),
            isFlatPrice: (bool) $price->getIsFlatPrice(),
            isPerRoom: $price->getIsPerRoom(),
            numberOfPersons: null !== $price->getNumberOfPersons() ? (int) $price->getNumberOfPersons() : null,
            minStay: null !== $price->getMinStay() ? (int) $price->getMinStay() : null,
            active: (bool) $price->getActive(),
            roomCategory: null !== $category
                ? ['id' => $category->getId(), 'name' => $category->getName()]
                : null,
            origins: $origins,
            allDays: (bool) $price->getAllDays(),
            weekdays: [
                'monday' => (bool) $price->getMonday(),
                'tuesday' => (bool) $price->getTuesday(),
                'wednesday' => (bool) $price->getWednesday(),
                'thursday' => (bool) $price->getThursday(),
                'friday' => (bool) $price->getFriday(),
                'saturday' => (bool) $price->getSaturday(),
                'sunday' => (bool) $price->getSunday(),
            ],
            allPeriods: (bool) $price->getAllPeriods(),
            periods: $periods,
            seasonStart: $price->getSeasonStart()?->format('Y-m-d'),
            seasonEnd: $price->getSeasonEnd()?->format('Y-m-d'),
            isBookableOnline: $price->getIsBookableOnline(),
            isMandatoryOnline: $price->getIsMandatoryOnline(),
            isDefaultActiveInReservationCreation: $price->getIsDefaultActiveInReservationCreation(),
            isPackage: $price->isPackage(),
            components: $components,
        );
    }
}
