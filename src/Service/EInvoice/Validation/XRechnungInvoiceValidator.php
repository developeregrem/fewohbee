<?php

declare(strict_types=1);

namespace App\Service\EInvoice\Validation;

use App\Entity\Enum\PaymentMeansCode;
use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;

// XRechnung (CIUS) rules: EN 16931 baseline plus the German BR-DE requirements.
class XRechnungInvoiceValidator implements EInvoiceValidatorInterface
{
    public function __construct(private En16931InvoiceValidator $baseValidator)
    {
    }

    public function validate(Invoice $invoice, InvoiceSettingsData $settings): EInvoiceValidationResult
    {
        $violations = $this->baseValidator->validate($invoice, $settings)->getViolations();

        // BR-DE-1: payment instructions are always required.
        if (!($invoice->getPaymentMeans() instanceof PaymentMeansCode)) {
            $violations[] = new EInvoiceViolation('paymentMeans', 'invoice.einvoice.violation.paymentMeans', EInvoiceFixLocation::INVOICE);
        }

        // BR-DE-15: buyer reference (Leitweg-ID) must be provided.
        if (null === $invoice->getBuyerReference() || '' === trim($invoice->getBuyerReference())) {
            $violations[] = new EInvoiceViolation('buyerReference', 'invoice.einvoice.violation.buyerReference', EInvoiceFixLocation::INVOICE);
        }

        // BR-DE-2/5/6/7: seller contact with name, phone and email.
        if (empty($settings->getContactName()) || empty($settings->getContactPhone()) || empty($settings->getContactMail())) {
            $violations[] = new EInvoiceViolation('sellerContact', 'invoice.einvoice.violation.sellerContact', EInvoiceFixLocation::SETTINGS);
        }

        // BT-34 seller electronic address (Peppol R020 / XRechnung): mandatory and written
        // unconditionally by the generator, so an empty value would produce an invalid endpoint.
        if (empty($settings->getCompanyInvoiceMail())) {
            $violations[] = new EInvoiceViolation('sellerElectronicAddress', 'invoice.einvoice.violation.sellerElectronicAddress', EInvoiceFixLocation::SETTINGS);
        }

        // Buyer electronic address (BT-49) must not be empty in XRechnung.
        if (empty($invoice->getEmail())) {
            $violations[] = new EInvoiceViolation('buyerEmail', 'invoice.einvoice.violation.buyerEmail', EInvoiceFixLocation::INVOICE);
        }

        // BR-DE-29: for SEPA direct debit the mandate reference (BT-89) is mandatory.
        if (PaymentMeansCode::SEPA_DIRECT_DEBIT === $invoice->getPaymentMeans()
            && (null === $invoice->getMandateReference() || '' === trim($invoice->getMandateReference()))) {
            $violations[] = new EInvoiceViolation('mandateReference', 'invoice.einvoice.violation.mandateReference', EInvoiceFixLocation::INVOICE);
        }

        return new EInvoiceValidationResult($violations);
    }
}
