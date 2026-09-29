<?php

declare(strict_types=1);

namespace App\Dto\Pricing;

use App\Entity\Price;
use App\Entity\PricePeriod;

/**
 * What a SpecialPriceRequest would change, computed without saving anything.
 *
 * Conflicts are other active apartment rows that would compete for the same nights (same
 * occupancy, minimum stay and at least one shared room category, origin and weekday). For each of
 * their overlapping periods, $remaining holds what is left once the new period is cut out: one
 * range when it is shortened, two when it is split, none when it is removed.
 *
 * Special rows with a different minimum stay on these nights are no conflict: per night the row
 * with the higher minimum stay wins once the stay is long enough. They are listed so the user can
 * see which stays keep which price.
 */
final readonly class SpecialPricePlan
{
    /**
     * @param list<array{price: Price, periods: list<array{period: PricePeriod, remaining: list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>}>}> $conflicts
     * @param list<array{id: int, startDate: string, endDate: string, apartmentNumber: string|null}>                                                         $affectedReservations
     * @param list<Price>                                                                                                                                     $otherStayLengths
     */
    public function __construct(
        public SpecialPriceRequest $request,
        public Price $source,
        public array $conflicts,
        public array $affectedReservations,
        public array $otherStayLengths = [],
    ) {
    }

    /** Conflicts block the change unless the request allows overwriting them. */
    public function canApply(): bool
    {
        return [] === $this->conflicts || $this->request->overwriteConflicts;
    }

    /**
     * Hash over the state the change depends on (source row and the periods it would cut), so a
     * confirmation given for this plan does not carry over to a changed price list.
     */
    public function fingerprint(): string
    {
        $conflicts = [];
        foreach ($this->conflicts as $conflict) {
            foreach ($conflict['periods'] as $entry) {
                $conflicts[] = [
                    $conflict['price']->getId(),
                    $entry['period']->getId(),
                    $entry['period']->getStart()?->format('Y-m-d'),
                    $entry['period']->getEnd()?->format('Y-m-d'),
                ];
            }
        }

        return hash('sha256', (string) json_encode([
            'request' => $this->request->fingerprint(),
            'source' => [$this->source->getId(), (string) $this->source->getPrice(), $this->source->getAllPeriods()],
            'conflicts' => $conflicts,
        ]));
    }
}
