<?php

declare(strict_types=1);

namespace App\Service\OnlineBooking;

/**
 * Fills in the room selection of step 2 when the search leaves only one choice.
 *
 * Step 2 starts with every quantity at zero, and guests tended to press
 * "continue" without raising one. When exactly one combination of the offered
 * occupancies fits the search — typically a house with a single room type, or a
 * party that only one category can take in the requested number of rooms —
 * there is nothing to decide, so that combination is preselected. Whenever the
 * guest has a real choice, nothing is preselected.
 *
 * "Fits" follows PublicBookingService::validateOccupancySelectionAgainstAvailability():
 * the quantities add up to the requested room count, their occupancies to the
 * party size, and no option or room type exceeds its available count.
 */
final class OccupancyPreselector
{
    /**
     * Upper bound on visited combinations. Large group searches over many room
     * types can have a huge search space; past this bound the choice counts as
     * open rather than slowing down the public page.
     */
    private const SEARCH_BUDGET = 5000;

    /**
     * @param array<int, array{typeKey: string, availableCount: int, occupancyOptions: array<int|string, array{persons: int}>, occupancyAvailableCounts?: array<int, int>}> $availability rows as returned by PublicAvailabilityService::getAvailability()
     *
     * @return array<string, array<int, int>> typeKey => [persons => quantity]; empty when the guest has a choice or nothing fits
     */
    public function preselect(array $availability, int $roomsCount, int $persons): array
    {
        if ($roomsCount < 1 || $persons < $roomsCount) {
            return [];
        }

        $slots = [];
        $typeLeft = [];
        foreach ($availability as $row) {
            $typeKey = $row['typeKey'];
            $typeLeft[$typeKey] = (int) $row['availableCount'];
            foreach ($row['occupancyOptions'] as $option) {
                $optionPersons = (int) $option['persons'];
                $slots[] = [
                    'typeKey' => $typeKey,
                    'persons' => $optionPersons,
                    'max' => min(
                        (int) ($row['occupancyAvailableCounts'][$optionPersons] ?? $row['availableCount']),
                        (int) $row['availableCount'],
                    ),
                ];
            }
        }

        // Largest occupancy among the remaining options, so the search can drop a
        // branch whose rooms could never seat the guests still left over.
        $reach = 0;
        for ($i = count($slots) - 1; $i >= 0; --$i) {
            $reach = max($reach, $slots[$i]['persons']);
            $slots[$i]['reach'] = $reach;
        }

        $solutions = [];
        $chosen = [];
        $budget = self::SEARCH_BUDGET;
        $this->search($slots, 0, $roomsCount, $persons, $typeLeft, $chosen, $solutions, $budget);

        if (1 !== count($solutions) || $budget < 0) {
            return [];
        }

        return $solutions[0];
    }

    /**
     * Depth-first search over the occupancy options, stopping at the second
     * solution — two are enough to know the guest has a choice.
     *
     * @param list<array{typeKey: string, persons: int, max: int, reach: int}> $slots
     * @param array<string, int>                                               $typeLeft  rooms still free per type
     * @param array<string, array<int, int>>                                   $chosen    selection on the current path
     * @param list<array<string, array<int, int>>>                             $solutions
     */
    private function search(
        array $slots,
        int $index,
        int $roomsLeft,
        int $personsLeft,
        array &$typeLeft,
        array &$chosen,
        array &$solutions,
        int &$budget,
    ): void {
        if (count($solutions) > 1 || --$budget < 0) {
            return;
        }
        if (0 === $roomsLeft) {
            if (0 === $personsLeft) {
                $solutions[] = $chosen;
            }

            return;
        }
        // Every room holds at least one guest and at most the largest remaining occupancy.
        if (!isset($slots[$index]) || $roomsLeft > $personsLeft || $personsLeft > $roomsLeft * $slots[$index]['reach']) {
            return;
        }

        $slot = $slots[$index];
        $limit = min($slot['max'], $roomsLeft, $typeLeft[$slot['typeKey']]);
        for ($qty = $limit; $qty >= 0; --$qty) {
            if ($qty * $slot['persons'] > $personsLeft) {
                continue;
            }

            if ($qty > 0) {
                $typeLeft[$slot['typeKey']] -= $qty;
                $chosen[$slot['typeKey']][$slot['persons']] = $qty;
            }

            $this->search($slots, $index + 1, $roomsLeft - $qty, $personsLeft - $qty * $slot['persons'], $typeLeft, $chosen, $solutions, $budget);

            if ($qty > 0) {
                $typeLeft[$slot['typeKey']] += $qty;
                unset($chosen[$slot['typeKey']][$slot['persons']]);
                if ([] === $chosen[$slot['typeKey']]) {
                    unset($chosen[$slot['typeKey']]);
                }
            }
        }
    }
}
