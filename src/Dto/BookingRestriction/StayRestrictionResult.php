<?php

declare(strict_types=1);

namespace App\Dto\BookingRestriction;

use App\Entity\BookingRestrictionRule;
use App\Entity\Enum\BookingRestrictionType;

/**
 * Why a stay is or is not bookable. A result never implies that a room is free or that a
 * price exists — it answers the restriction question alone.
 */
final readonly class StayRestrictionResult
{
    /**
     * @param BookingRestrictionType|null $reason   null when the stay passes every restriction
     * @param BookingRestrictionRule|null $rule     the rule that decided, for naming it in the UI
     * @param \DateTimeImmutable|null     $ruleDate the arrival, night or departure date the rule acted on
     */
    public function __construct(
        public int $nights,
        public int $requiredNights,
        public ?BookingRestrictionType $reason = null,
        public ?BookingRestrictionRule $rule = null,
        public ?\DateTimeImmutable $ruleDate = null,
    ) {
    }

    public function isAllowed(): bool
    {
        return null === $this->reason;
    }
}
