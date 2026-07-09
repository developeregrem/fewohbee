<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use App\Service\EInvoice\EInvoiceProfileGeneratorInterface;
use App\Service\EInvoice\Validation\EInvoiceValidationResult;
use App\Service\EInvoice\Validation\XRechnungInvoiceValidator;
use App\Service\EInvoice\ZugferdInvoiceGenerator;
use horstoeko\zugferd\ZugferdProfiles;

// XRechnung profile generator wrapper.
class XRechnungService implements EInvoiceProfileGeneratorInterface
{
    // Uses the shared ZUGFeRD generator with XRechnung profile id.
    public function __construct(private ZugferdInvoiceGenerator $generator, private XRechnungInvoiceValidator $validator)
    {
    }

    // Profile key stored in settings.
    public function getProfileKey(): string
    {
        return 'xrechnung';
    }

    // Label translation key for forms.
    public function getLabelKey(): string
    {
        return 'invoice.settings.einvoiceProfile.xrechnung';
    }

    // Checks EN 16931 baseline plus BR-DE rules.
    public function validate(Invoice $invoice, InvoiceSettingsData $settings): EInvoiceValidationResult
    {
        return $this->validator->validate($invoice, $settings);
    }

    // Generates the invoice data for XRechnung.
    public function generateInvoiceData(Invoice $invoice, InvoiceSettingsData $settings): string
    {
        return $this->generator->generateInvoiceData($invoice, $settings, ZugferdProfiles::PROFILE_XRECHNUNG_3, $this->validator);
    }
}
