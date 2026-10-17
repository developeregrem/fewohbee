<?php

declare(strict_types=1);

namespace App\Dto\GuestCheckIn;

/**
 * The hotelier's choices when confirming an online check-in, i.e. taking it over into the guest
 * records.
 *
 * Targets are "booker", "new", "skip" (companions only), "customer:<id>" for a linked guest,
 * or "existing:<id>" for a verified global candidate when customer access is granted. Choosing a
 * record whose name differs from the submission is deliberate: the review never preselects one.
 */
final class GuestCheckInApplyRequest
{
    public const TARGET_BOOKER = 'booker';
    public const TARGET_NEW = 'new';
    public const TARGET_SKIP = 'skip';
    public const TARGET_CUSTOMER_PREFIX = 'customer:';
    public const TARGET_EXISTING_PREFIX = 'existing:';

    /**
     * @param list<string> $companionTargets one per submitted fellow traveller, in order
     * @param list<int>    $removeGuestIds   guests linked to the reservation who do not travel
     */
    public function __construct(
        public readonly string $mainTarget,
        public readonly bool $setAsBooker,
        public readonly array $companionTargets,
        public readonly ?string $expectedSubmissionVersion = null,
        public readonly bool $applyExtras = true,
        public readonly array $removeGuestIds = [],
        public readonly bool $allowGlobalMatch = false,
    ) {
    }
}
