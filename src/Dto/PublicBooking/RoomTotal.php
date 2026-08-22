<?php

declare(strict_types=1);

namespace App\Dto\PublicBooking;

use App\Entity\InvoicePosition;

/**
 * What one reservation costs, with the per-guest adjustments kept separate.
 *
 * The room rate and the modifier deltas (a child's reduced rate, an extra-bed
 * surcharge) are one number on the invoice line but two things to the guest: the
 * price of the room, and what their particular party changes about it. Keeping
 * them apart lets the booking page show the second as its own line instead of
 * silently folding it into the first — which is what made the total in step 3
 * differ from the price advertised in step 2.
 */
final readonly class RoomTotal
{
    /**
     * @param InvoicePosition[] $modifierPositions the deltas behind $modifiers, carrying their labels
     */
    public function __construct(
        public float $room,
        public float $modifiers,
        public array $modifierPositions = [],
    ) {
    }

    /** Room rate and adjustments combined — what the stay actually costs. */
    public function total(): float
    {
        return $this->room + $this->modifiers;
    }
}
