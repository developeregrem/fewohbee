<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn\Section;

use App\Entity\GuestCheckIn;
use App\Service\GuestCheckIn\GuestCheckInPolicy;

/**
 * Collects the page sections of all providers and applies the visibility rules centrally, so
 * content meant for the stay (a door code, say) cannot slip out through a provider that forgot
 * to check.
 */
class GuestCheckInSections
{
    /**
     * @param iterable<GuestCheckInSectionProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly GuestCheckInPolicy $policy,
    ) {
    }

    /** @return list<GuestCheckInSection> */
    public function visibleFor(GuestCheckIn $checkIn): array
    {
        $sections = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->getSections($checkIn) as $section) {
                if ($this->policy->isSectionVisible($section, $checkIn)) {
                    $sections[] = $section;
                }
            }
        }

        return $sections;
    }
}
