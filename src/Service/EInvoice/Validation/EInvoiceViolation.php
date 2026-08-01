<?php

declare(strict_types=1);

namespace App\Service\EInvoice\Validation;

// A single missing/invalid field preventing e-invoice generation.
final readonly class EInvoiceViolation
{
    public function __construct(
        public string $field,
        public string $messageKey,
        public EInvoiceFixLocation $fixLocation,
    ) {
    }
}
