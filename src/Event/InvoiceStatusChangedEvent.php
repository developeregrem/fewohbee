<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Invoice;

class InvoiceStatusChangedEvent
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly int $previousStatus,
    ) {
    }
}
