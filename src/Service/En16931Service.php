<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use App\Service\EInvoice\EInvoiceProfileGeneratorInterface;
use App\Service\EInvoice\ZugferdInvoiceGenerator;
use horstoeko\zugferd\ZugferdProfiles;

// EN 16931 profile generator wrapper.
class En16931Service implements EInvoiceProfileGeneratorInterface
{
    // Uses the shared ZUGFeRD generator with EN16931 profile id.
    public function __construct(private ZugferdInvoiceGenerator $generator)
    {
    }

    // Profile key stored in settings.
    public function getProfileKey(): string
    {
        return 'en16931';
    }

    // Label translation key for forms.
    public function getLabelKey(): string
    {
        return 'invoice.settings.einvoiceProfile.en16931';
    }

    // Generates the invoice data for EN 16931.
    public function generateInvoiceData(Invoice $invoice, InvoiceSettingsData $settings): string
    {
        return $this->generator->generateInvoiceData($invoice, $settings, ZugferdProfiles::PROFILE_EN16931);
    }
}
