<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\GuestStatisticalGroup;
use App\Entity\GuestCategory;
use App\Repository\GuestCategoryRepository;

/**
 * Maps the public-wizard's "Adults + per-child ages" input onto the existing
 * `guestCounts` map keyed by GuestCategory id. The wizard intentionally does
 * not expose category names to the guest; the server resolves the correct
 * category from the age each guest entered.
 */
class GuestCategoryAgeMapper
{
    public function __construct(
        private readonly GuestCategoryRepository $guestCategoryRepository,
    ) {
    }

    /**
     * Build a guestCounts map from the wizard input.
     *
     * Adults are bucketed into the lowest-sortOrder ADULT category.
     * Each child age is matched to the category whose [minAge, maxAge] range
     * covers the age (NULL bounds = unbounded). Tie-breaker is sortOrder, then id.
     * If no category matches a given age, that child is silently dropped so
     * the booking can still proceed; misconfiguration should be caught by the
     * hotelier later (logged warning could be added).
     *
     * The mapper is strict about age matching — the wizard UI is responsible
     * for collecting ages whenever there is more than one non-ADULT category
     * or whenever the only one has age bounds. A category with both bounds
     * NULL serves as a catch-all that matches every age.
     *
     * @param int   $adults     count of adults (≥ 0)
     * @param int[] $childAges  list of ages, one entry per child
     *
     * @return array<int, int> categoryId => count
     */
    public function map(int $adults, array $childAges): array
    {
        $categories = array_filter(
            $this->guestCategoryRepository->findActiveOrdered(),
            static fn (GuestCategory $c) => GuestStatisticalGroup::OTHER !== $c->getStatisticalGroup(),
        );

        $adultCategory = null;
        $childCategories = [];
        foreach ($categories as $c) {
            if (GuestStatisticalGroup::ADULT === $c->getStatisticalGroup()) {
                if (null === $adultCategory || $c->getSortOrder() < $adultCategory->getSortOrder()) {
                    $adultCategory = $c;
                }
            } else {
                $childCategories[] = $c;
            }
        }

        $counts = [];
        if ($adults > 0 && null !== $adultCategory) {
            $counts[(int) $adultCategory->getId()] = $adults;
        }

        if ([] === $childCategories) {
            return $counts;
        }

        foreach ($childAges as $age) {
            $age = (int) $age;
            if ($age < 0) {
                continue;
            }
            $match = $this->matchByAge($childCategories, $age);
            if (null === $match) {
                continue;
            }
            $id = (int) $match->getId();
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param GuestCategory[] $categories
     */
    private function matchByAge(array $categories, int $age): ?GuestCategory
    {
        $best = null;
        foreach ($categories as $c) {
            $minAge = $c->getMinAge();
            $maxAge = $c->getMaxAge();
            if (null !== $minAge && $age < $minAge) {
                continue;
            }
            if (null !== $maxAge && $age > $maxAge) {
                continue;
            }
            if (null === $best
                || $c->getSortOrder() < $best->getSortOrder()
                || ($c->getSortOrder() === $best->getSortOrder() && (int) $c->getId() < (int) $best->getId())
            ) {
                $best = $c;
            }
        }

        return $best;
    }
}
