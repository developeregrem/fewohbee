<?php

declare(strict_types=1);

namespace App\Service\EInvoice\Validation;

use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;

// Validates whether an e-invoice can be generated for a profile; collects all violations.
interface EInvoiceValidatorInterface
{
    public function validate(Invoice $invoice, InvoiceSettingsData $settings): EInvoiceValidationResult;
}
