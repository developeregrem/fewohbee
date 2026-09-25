<?php

declare(strict_types=1);

namespace App\Dto\GuestCheckIn;

/**
 * The hotelier's choices when taking an online check-in over into the guest records.
 *
 * Targets are "booker", "new", "skip" (companions only) or "customer:<id>" for a guest already
 * linked to the reservation; anything else is rejected by GuestCheckInApplyService.
 */
final class GuestCheckInApplyRequest
{
    public const TARGET_BOOKER = 'booker';
    public const TARGET_NEW = 'new';
    public const TARGET_SKIP = 'skip';
    public const TARGET_CUSTOMER_PREFIX = 'customer:';

    /**
     * @param list<string> $companionTargets one per submitted fellow traveller, in order
     */
    public function __construct(
        public readonly string $mainTarget,
        public readonly bool $setAsBooker,
        public readonly array $companionTargets,
    ) {
    }
}
