<?php

declare(strict_types=1);

namespace App\Dto\GuestCheckIn;

/**
 * The hotelier's choices when taking an online check-in over into the guest records.
 *
 * Targets are "booker", "new", "skip" (companions only), "customer:<id>" for a linked guest,
 * or "existing:<id>" for a verified global candidate when customer access is granted.
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
     */
    public function __construct(
        public readonly string $mainTarget,
        public readonly bool $setAsBooker,
        public readonly array $companionTargets,
        public readonly ?string $expectedSubmissionVersion = null,
        public readonly bool $confirmBookerMismatch = false,
        public readonly bool $applyExtras = true,
        public readonly bool $removeBookerFromGuests = false,
        public readonly bool $allowGlobalMatch = false,
    ) {
    }
}
