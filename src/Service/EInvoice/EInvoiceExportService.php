<?php

declare(strict_types=1);

namespace App\Service\EInvoice;

use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use App\Service\EInvoice\Validation\EInvoiceValidationResult;

// Export service that routes invoices to the selected profile generator.
class EInvoiceExportService
{
    public const DEFAULT_PROFILE = 'en16931';

    public function __construct(private EInvoiceProfileRegistry $registry)
    {
    }

    // Resolves the profile key configured in settings, falling back to the default profile.
    public function resolveProfileKey(InvoiceSettingsData $settings): string
    {
        return $settings->getEinvoiceProfile() ?: self::DEFAULT_PROFILE;
    }

    // Central pre-check: collects all missing mandatory fields for the configured profile.
    public function validateInvoice(Invoice $invoice, InvoiceSettingsData $settings): EInvoiceValidationResult
    {
        return $this->registry->getProfile($this->resolveProfileKey($settings))->validate($invoice, $settings);
    }

    // Generates invoice data using the profile configured in settings.
    public function generateInvoiceData(Invoice $invoice, InvoiceSettingsData $settings): string
    {
        return $this->registry->getProfile($this->resolveProfileKey($settings))->generateInvoiceData($invoice, $settings);
    }
}
