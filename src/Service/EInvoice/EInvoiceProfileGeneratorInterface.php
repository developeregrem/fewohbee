<?php

declare(strict_types=1);

namespace App\Service\EInvoice;

use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use App\Service\EInvoice\Validation\EInvoiceValidationResult;

// Interface for pluggable e-invoice profile generators.
interface EInvoiceProfileGeneratorInterface
{
    // Returns the internal profile key stored in settings.
    public function getProfileKey(): string;

    // Returns translation key for the profile label.
    public function getLabelKey(): string;

    // Checks all profile-specific mandatory fields without generating anything.
    public function validate(Invoice $invoice, InvoiceSettingsData $settings): EInvoiceValidationResult;

    // Generates the invoice payload for the configured profile.
    public function generateInvoiceData(Invoice $invoice, InvoiceSettingsData $settings): string;
}
