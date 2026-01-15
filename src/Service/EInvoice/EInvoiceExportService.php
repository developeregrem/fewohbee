<?php

declare(strict_types=1);

namespace App\Service\EInvoice;

use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;

// Export service that routes invoices to the selected profile generator.
class EInvoiceExportService
{
    public function __construct(private EInvoiceProfileRegistry $registry)
    {
    }

    // Generates invoice data using the profile configured in settings.
    public function generateInvoiceData(Invoice $invoice, InvoiceSettingsData $settings): string
    {
        $profileKey = $settings->getEinvoiceProfile() ?: 'xrechnung';
        $profile = $this->registry->getProfile($profileKey);

        return $profile->generateInvoiceData($invoice, $settings);
    }
}
