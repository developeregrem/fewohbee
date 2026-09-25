<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn\Section;

/**
 * A block of information for the guest on the check-in page, e.g. arrival instructions.
 *
 * The visibility flags are enforced centrally (GuestCheckInSections), not by the provider:
 * sensitive content such as a door code must not depend on every provider getting it right.
 */
final readonly class GuestCheckInSection
{
    /**
     * @param string               $template Twig template rendering the block
     * @param array<string, mixed> $vars     variables for that template
     * @param bool                 $requiresSubmission shown only once the guest sent the form
     * @param bool                 $duringStayOnly     shown only from the arrival day to the departure day
     */
    public function __construct(
        public string $key,
        public string $template,
        public array $vars = [],
        public bool $requiresSubmission = false,
        public bool $duringStayOnly = false,
    ) {
    }
}
