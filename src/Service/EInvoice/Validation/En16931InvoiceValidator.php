<?php

declare(strict_types=1);

namespace App\Service\EInvoice\Validation;

use App\Entity\Enum\PaymentMeansCode;
use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;

// Baseline EN 16931 rules shared by all ZUGFeRD-based profiles.
class En16931InvoiceValidator implements EInvoiceValidatorInterface
{
    public function validate(Invoice $invoice, InvoiceSettingsData $settings): EInvoiceValidationResult
    {
        $violations = [];

        // BR-2 / BR-3: invoice number (BT-1) and issue date (BT-2). Normally filled by the creation
        // flow, but checked here so persisted/programmatic edge cases yield a clean violation instead
        // of a builder/validator crash.
        if (empty($invoice->getNumber())) {
            $violations[] = new EInvoiceViolation('invoiceNumber', 'invoice.einvoice.violation.invoiceNumber', EInvoiceFixLocation::INVOICE);
        }
        // Invoice::getDate() is typed non-nullable and throws on an unset date, so guard the access.
        try {
            $hasDate = $invoice->getDate() instanceof \DateTimeInterface;
        } catch (\TypeError) {
            $hasDate = false;
        }
        if (!$hasDate) {
            $violations[] = new EInvoiceViolation('invoiceDate', 'invoice.einvoice.violation.invoiceDate', EInvoiceFixLocation::INVOICE);
        }

        // Buyer (BG-8): name, full postal address with street, post code + city and country (BR-11).
        if (empty($invoice->getLastname()) && empty($invoice->getCompany())) {
            $violations[] = new EInvoiceViolation('buyerName', 'invoice.einvoice.violation.buyerName', EInvoiceFixLocation::INVOICE);
        }
        if (empty($invoice->getAddress())) {
            $violations[] = new EInvoiceViolation('buyerStreet', 'invoice.einvoice.violation.buyerStreet', EInvoiceFixLocation::INVOICE);
        }
        if (empty($invoice->getZip()) || empty($invoice->getCity())) {
            $violations[] = new EInvoiceViolation('buyerPostCodeCity', 'invoice.einvoice.violation.buyerPostCodeCity', EInvoiceFixLocation::INVOICE);
        }
        if (empty($invoice->getCountry())) {
            $violations[] = new EInvoiceViolation('buyerCountry', 'invoice.einvoice.violation.buyerCountry', EInvoiceFixLocation::INVOICE);
        }

        // Seller master data the generator always writes: name (BR-6), full postal address (BR-8) and
        // country (BR-9).
        if (empty($settings->getCompanyName())) {
            $violations[] = new EInvoiceViolation('sellerName', 'invoice.einvoice.violation.sellerName', EInvoiceFixLocation::SETTINGS);
        }
        if (empty($settings->getCompanyAddress()) || empty($settings->getCompanyPostCode()) || empty($settings->getCompanyCity()) || empty($settings->getCompanyCountry())) {
            $violations[] = new EInvoiceViolation('sellerAddress', 'invoice.einvoice.violation.sellerAddress', EInvoiceFixLocation::SETTINGS);
        }

        // BR-CO-26: the buyer must be able to identify the seller via BT-30 (legal registration id) or
        // BT-31 (VAT id). The German tax number is written as BT-32 and does NOT satisfy this rule, so
        // at least a VAT id or a legal registration number must be present.
        if (empty($settings->getVatID()) && empty($settings->getRegistrationNumber())) {
            $violations[] = new EInvoiceViolation('sellerIdentifier', 'invoice.einvoice.violation.sellerIdentifier', EInvoiceFixLocation::SETTINGS);
        }

        if (empty($settings->getPaymentDueDays()) && empty($settings->getPaymentTerms())) {
            $violations[] = new EInvoiceViolation('paymentTerms', 'invoice.einvoice.violation.paymentTerms', EInvoiceFixLocation::SETTINGS);
        }

        // Positions of an invoice in creation live in the session, not on the entity yet.
        if (null !== $invoice->getId() && 0 === count($invoice->getAppartments()) && 0 === count($invoice->getPositions())) {
            $violations[] = new EInvoiceViolation('positions', 'invoice.einvoice.violation.positions', EInvoiceFixLocation::INVOICE);
        }

        return new EInvoiceValidationResult([...$violations, ...$this->validatePaymentMeans($invoice, $settings)]);
    }

    /**
     * Payment means are optional in EN 16931; when set, the payment-specific fields must be complete.
     *
     * @return EInvoiceViolation[]
     */
    protected function validatePaymentMeans(Invoice $invoice, InvoiceSettingsData $settings): array
    {
        $violations = [];
        $paymentMeans = $invoice->getPaymentMeans();

        if (PaymentMeansCode::SEPA_CREDIT_TRANSFER === $paymentMeans && empty($settings->getAccountIBAN())) {
            $violations[] = new EInvoiceViolation('accountIBAN', 'invoice.einvoice.violation.accountIBAN', EInvoiceFixLocation::SETTINGS);
        }

        if (PaymentMeansCode::CREDIT_TRANSFER === $paymentMeans && (empty($settings->getAccountIBAN()) || empty($settings->getAccountBIC()))) {
            $violations[] = new EInvoiceViolation('accountIBANBIC', 'invoice.einvoice.violation.accountIBANBIC', EInvoiceFixLocation::SETTINGS);
        }

        if (PaymentMeansCode::CARD_PAYMENT === $paymentMeans && empty($invoice->getCardNumber())) {
            $violations[] = new EInvoiceViolation('cardNumber', 'invoice.einvoice.violation.cardNumber', EInvoiceFixLocation::INVOICE);
        }

        if (PaymentMeansCode::SEPA_DIRECT_DEBIT === $paymentMeans) {
            // Debited account IBAN (BT-91) is needed to perform the debit in both profiles.
            // The mandate reference (BT-89) is only mandatory for XRechnung (BR-DE-29) → handled there.
            if (empty($invoice->getCustomerIBAN())) {
                $violations[] = new EInvoiceViolation('customerIBAN', 'invoice.einvoice.violation.customerIBAN', EInvoiceFixLocation::INVOICE);
            }
            if (empty($settings->getCreditorReference())) {
                $violations[] = new EInvoiceViolation('creditorReference', 'invoice.einvoice.violation.creditorReference', EInvoiceFixLocation::SETTINGS);
            }
        }

        return $violations;
    }
}
