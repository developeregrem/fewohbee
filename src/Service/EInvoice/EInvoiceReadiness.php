<?php

declare(strict_types=1);

namespace App\Service\EInvoice;

use App\Service\EInvoice\Validation\EInvoiceValidationResult;

// Snapshot of whether an e-invoice can be generated for a given invoice.
final readonly class EInvoiceReadiness
{
    public function __construct(
        public bool $configured,
        public bool $ready,
        public ?string $profileKey,
        public ?EInvoiceValidationResult $result,
    ) {
    }
}
