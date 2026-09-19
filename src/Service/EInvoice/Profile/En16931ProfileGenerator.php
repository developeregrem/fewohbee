<?php

declare(strict_types=1);

namespace App\Service\EInvoice\Profile;

use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use App\Service\EInvoice\EInvoiceProfileGeneratorInterface;
use App\Service\EInvoice\Validation\En16931InvoiceValidator;
use App\Service\EInvoice\Validation\EInvoiceValidationResult;
use App\Service\EInvoice\ZugferdInvoiceGenerator;
use horstoeko\zugferd\ZugferdProfiles;

/**
 * Generates and validates electronic invoices using the EN 16931 profile.
 */
final class En16931ProfileGenerator implements EInvoiceProfileGeneratorInterface
{
    public function __construct(
        private readonly ZugferdInvoiceGenerator $generator,
        private readonly En16931InvoiceValidator $validator,
    ) {
    }

    public function getProfileKey(): string
    {
        return 'en16931';
    }

    public function getLabelKey(): string
    {
        return 'invoice.settings.einvoiceProfile.en16931';
    }

    public function validate(Invoice $invoice, InvoiceSettingsData $settings): EInvoiceValidationResult
    {
        return $this->validator->validate($invoice, $settings);
    }

    /** Generate validated EN 16931 XML through the shared ZUGFeRD generator. */
    public function generateInvoiceData(Invoice $invoice, InvoiceSettingsData $settings): string
    {
        return $this->generator->generateInvoiceData(
            $invoice,
            $settings,
            ZugferdProfiles::PROFILE_EN16931,
            $this->validator,
        );
    }
}
