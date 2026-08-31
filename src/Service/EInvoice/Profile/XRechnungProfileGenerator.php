<?php

declare(strict_types=1);

namespace App\Service\EInvoice\Profile;

use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use App\Service\EInvoice\EInvoiceProfileGeneratorInterface;
use App\Service\EInvoice\Validation\EInvoiceValidationResult;
use App\Service\EInvoice\Validation\XRechnungInvoiceValidator;
use App\Service\EInvoice\ZugferdInvoiceGenerator;
use horstoeko\zugferd\ZugferdProfiles;

/**
 * Generates and validates electronic invoices using the German XRechnung profile.
 */
final class XRechnungProfileGenerator implements EInvoiceProfileGeneratorInterface
{
    public function __construct(
        private readonly ZugferdInvoiceGenerator $generator,
        private readonly XRechnungInvoiceValidator $validator,
    ) {
    }

    public function getProfileKey(): string
    {
        return 'xrechnung';
    }

    public function getLabelKey(): string
    {
        return 'invoice.settings.einvoiceProfile.xrechnung';
    }

    public function validate(Invoice $invoice, InvoiceSettingsData $settings): EInvoiceValidationResult
    {
        return $this->validator->validate($invoice, $settings);
    }

    /** Generate validated XRechnung XML through the shared ZUGFeRD generator. */
    public function generateInvoiceData(Invoice $invoice, InvoiceSettingsData $settings): string
    {
        return $this->generator->generateInvoiceData(
            $invoice,
            $settings,
            ZugferdProfiles::PROFILE_XRECHNUNG_3,
            $this->validator,
        );
    }
}
