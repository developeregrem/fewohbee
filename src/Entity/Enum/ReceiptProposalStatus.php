<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/** Progress of a receipt an AI assistant read and handed in for booking. */
enum ReceiptProposalStatus: string
{
    /** Waiting for a person to check and book it. */
    case OPEN = 'open';
    /** Booked in the journal. */
    case BOOKED = 'booked';
    /** Thrown away without booking. */
    case DISCARDED = 'discarded';

    public function labelKey(): string
    {
        return 'accounting.receipt_proposal.status.'.$this->value;
    }
}
