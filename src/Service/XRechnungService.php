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
use App\Service\EInvoice\ZugferdInvoiceGenerator;
use horstoeko\zugferd\ZugferdProfiles;

// XRechnung profile generator wrapper.
class XRechnungService implements EInvoiceProfileGeneratorInterface
{
    // Uses the shared ZUGFeRD generator with XRechnung profile id.
    public function __construct(private ZugferdInvoiceGenerator $generator)
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

    // Generates the invoice data for XRechnung.
    public function generateInvoiceData(Invoice $invoice, InvoiceSettingsData $settings): string
    {
        return $this->generator->generateInvoiceData($invoice, $settings, ZugferdProfiles::PROFILE_XRECHNUNG_3);
    }

    // Backward-compatible method for existing callers.
    public function createInvoice(Invoice $invoice, InvoiceSettingsData $settings): string
    {
        return $this->generateInvoiceData($invoice, $settings);
    }
}
