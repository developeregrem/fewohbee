<?php

declare(strict_types=1);

namespace App\Service\EInvoice;

use App\Entity\Enum\InvoiceStatus;
use App\Entity\Enum\PaymentMeansCode;
use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use App\Service\AppSettingsService;
use App\Service\EInvoice\Validation\EInvoiceValidationException;
use App\Service\EInvoice\Validation\EInvoiceValidatorInterface;
use horstoeko\zugferd\codelists\ZugferdCurrencyCodes;
use horstoeko\zugferd\codelists\ZugferdElectronicAddressScheme;
use horstoeko\zugferd\codelists\ZugferdInvoiceType;
use horstoeko\zugferd\codelists\ZugferdPaymentMeans;
use horstoeko\zugferd\codelists\ZugferdUnitCodes;
use horstoeko\zugferd\codelists\ZugferdVatCategoryCodes;
use horstoeko\zugferd\codelists\ZugferdVatTypeCodes;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use Symfony\Contracts\Translation\TranslatorInterface;

// Shared generator for ZUGFeRD-based profiles.
class ZugferdInvoiceGenerator
{
    public function __construct(private TranslatorInterface $translator, private AppSettingsService $appSettingsService)
    {
    }

    // Generates ZUGFeRD XML for a specific profile id after validating all mandatory fields.
    public function generateInvoiceData(Invoice $invoice, InvoiceSettingsData $settings, int $profile, EInvoiceValidatorInterface $validator): string
    {
        $result = $validator->validate($invoice, $settings);
        if (!$result->isValid()) {
            throw new EInvoiceValidationException($result);
        }

        $documentBuilder = ZugferdDocumentBuilder::createNew($profile);

        // General invoice Information
        $documentBuilder->setDocumentInformation(
            $invoice->getNumber(),                                     // Invoice number (BT-1)
            ZugferdInvoiceType::INVOICE,                               // Type "Invoice" (BT-3)
            $invoice->getDate(),                                       // Invoice date (BT-2)
            $this->resolveCurrencyCode(),                              // Currency from app settings, fallback EUR (BT-5)
            $this->translator->trans('invoice.number.short').'-'.$invoice->getNumber(), // A document title
        );

        // seller information
        $documentBuilder->setDocumentSeller($settings->getCompanyName()); // company name of the hotel
        // Tax number (BT-32) and VAT id (BT-31) only when present, otherwise an empty registration
        // element would be written. BR-CO-26 requires the VAT id (validated beforehand).
        if (!empty($settings->getTaxNumber())) {
            $documentBuilder->addDocumentSellerTaxNumber($settings->getTaxNumber());
        }
        if (!empty($settings->getVatID())) {
            $documentBuilder->addDocumentSellerVATRegistrationNumber($settings->getVatID());
        }
        // Legal registration identifier (BT-30, e.g. Handelsregisternummer): satisfies BR-CO-26 when
        // no VAT id is available. Scheme id (BT-30-1) is left empty as the German register number has
        // no common ISO/IEC 6523 code.
        if (!empty($settings->getRegistrationNumber())) {
            $documentBuilder->setDocumentSellerLegalOrganisation($settings->getRegistrationNumber(), null, $settings->getCompanyName());
        }
        $documentBuilder->setDocumentSellerAddress($settings->getCompanyAddress(), '', '', $settings->getCompanyPostCode(), $settings->getCompanyCity(), $settings->getCompanyCountry());
        // Seller contact (BG-6) is optional in EN 16931; only written when at least one field is present.
        if (!empty($settings->getContactName()) || !empty($settings->getContactPhone()) || !empty($settings->getContactMail())) {
            $documentBuilder->setDocumentSellerContact($settings->getContactName(), $settings->getContactDepartment(), $settings->getContactPhone(), null, $settings->getContactMail());
        }
        // Seller electronic address (BT-34): mandatory for XRechnung, omitted for ZUGFeRD when empty.
        if (!empty($settings->getCompanyInvoiceMail())) {
            $documentBuilder->setDocumentSellerCommunication(ZugferdElectronicAddressScheme::UNECE3155_EM, $settings->getCompanyInvoiceMail());
        }

        // customer information
        $customerName = $invoice->getSalutation().' '.$invoice->getFirstname().' '.$invoice->getLastname();
        $documentBuilder->setDocumentBuyer(!empty($invoice->getCompany()) ? $invoice->getCompany() : $customerName);
        $documentBuilder->setDocumentBuyerAddress($invoice->getAddress(), '', '', $invoice->getZip(), $invoice->getCity(), $invoice->getCountry());
        $documentBuilder->setDocumentBuyerContact($customerName, null, $invoice->getPhone(), null, $invoice->getEmail());
        // Buyer electronic address (BT-49) must not be written empty; mandatory for XRechnung via validator.
        if (!empty($invoice->getEmail())) {
            $documentBuilder->setDocumentBuyerCommunication(ZugferdElectronicAddressScheme::UNECE3155_EM, $invoice->getEmail());
        }

        if (!empty($invoice->getBuyerVatId())) {
            $documentBuilder->addDocumentBuyerVATRegistrationNumber($invoice->getBuyerVatId());
        }

        $mandateReference = null;
        if (PaymentMeansCode::CASH === $invoice->getPaymentMeans()) {
            $documentBuilder->addDocumentPaymentMean(ZugferdPaymentMeans::UNTDID_4461_10);
        }
        // CREDIT TRANSFER (BG-17) must be supplied with IBAN (mandatory) and BIC (optional)
        if (PaymentMeansCode::SEPA_CREDIT_TRANSFER === $invoice->getPaymentMeans()) {
            $documentBuilder->addDocumentPaymentMeanToCreditTransfer($settings->getAccountIBAN(), $settings->getAccountName(), null, $settings->getAccountBIC()); // Payment information
        }
        // CREDIT TRANSFER (BG-17) must be supplied with IBAN (mandatory) and BIC (mandatory)
        if (PaymentMeansCode::CREDIT_TRANSFER === $invoice->getPaymentMeans()) {
            $documentBuilder->addDocumentPaymentMeanToCreditTransferNonSepa($settings->getAccountIBAN(), $settings->getAccountName(), null, $settings->getAccountBIC()); // Payment information
        }
        // CARD INFORMATION (BG-18) must be supplied with card number (mandatory) and card holder (optional)
        if (PaymentMeansCode::CARD_PAYMENT === $invoice->getPaymentMeans()) {
            $documentBuilder->addDocumentPaymentMeanToPaymentCard('', $invoice->getCardNumberShort(), $invoice->getCardHolder());
        }

        // DIRECT DEBIT (BG-19) must be supplied with buyer IBAN (mandatory) and creditor identifier (mandatory)
        if (PaymentMeansCode::SEPA_DIRECT_DEBIT === $invoice->getPaymentMeans()) {
            $documentBuilder->addDocumentPaymentMeanToDirectDebit($invoice->getCustomerIBAN(), $settings->getCreditorReference());
            $mandateReference = $invoice->getMandateReference();
        }

        // payment terms and due date - the same date the invoice template prints,
        // so the XML and the paper never state different deadlines
        $documentBuilder->addDocumentPaymentTerm($settings->getPaymentTerms(), $settings->dueDateFor($invoice->getDate()), $mandateReference); // Payment term
        // Buyer reference (BT-10, Leitweg-ID): mandatory for XRechnung via validator, omitted otherwise.
        $buyerReference = $invoice->getBuyerReference();
        if (null !== $buyerReference && '' !== trim($buyerReference)) {
            $documentBuilder->setDocumentBuyerReference($buyerReference);
        }

        // invoice positions
        $pos = 1;
        $netSums = [];
        /* @var $apartmentPosition \App\Entity\InvoiceAppartment */
        foreach ($invoice->getAppartments() as $apartmentPosition) {
            $netPrice = round($apartmentPosition->getNetPrice(), 2);
            $sum = round($netPrice * $apartmentPosition->getAmount(), 2);
            $documentBuilder->addNewPosition((string) $pos);
            $documentBuilder->setDocumentPositionProductDetails($apartmentPosition->getDescription(), $apartmentPosition->getStartDate()->format('d.m.Y').' - '.$apartmentPosition->getEndDate()->format('d.m.Y'));
            $documentBuilder->setDocumentPositionNetPrice($netPrice);
            $documentBuilder->setDocumentPositionQuantity($apartmentPosition->getAmount(), ZugferdUnitCodes::REC20_PIECE);
            $documentBuilder->addDocumentPositionTax($this->resolveVatCategory($apartmentPosition->getVat()), ZugferdVatTypeCodes::VALUE_ADDED_TAX, $apartmentPosition->getVat());
            $documentBuilder->setDocumentPositionLineSummation($sum);
            $netSums[$apartmentPosition->getVat()] = ($netSums[$apartmentPosition->getVat()] ?? 0) + $sum;
            ++$pos;
        }

        // invoice misc positions
        /* @var $miscPosition \App\Entity\InvoicePosition */
        foreach ($invoice->getPositions() as $miscPosition) {
            $netPrice = round($miscPosition->getNetPrice(), 2);
            $sum = round($netPrice * $miscPosition->getAmount(), 2);
            $documentBuilder->addNewPosition((string) $pos);
            $documentBuilder->setDocumentPositionProductDetails($miscPosition->getDescription(), '');
            $documentBuilder->setDocumentPositionNetPrice($netPrice);
            $documentBuilder->setDocumentPositionQuantity($miscPosition->getAmount(), ZugferdUnitCodes::REC20_PIECE);
            $documentBuilder->addDocumentPositionTax($this->resolveVatCategory($miscPosition->getVat()), ZugferdVatTypeCodes::VALUE_ADDED_TAX, $miscPosition->getVat());
            $documentBuilder->setDocumentPositionLineSummation($sum);
            $netSums[$miscPosition->getVat()] = ($netSums[$miscPosition->getVat()] ?? 0) + $sum;
            ++$pos;
        }

        $vatSum = 0;
        $netSum = 0;
        foreach ($netSums as $vat => $sum) {
            $documentBuilder->addDocumentTax($this->resolveVatCategory((float) $vat), ZugferdVatTypeCodes::VALUE_ADDED_TAX, $sum, $sum * ($vat / 100), $vat);
            $vatSum += round($sum * ($vat / 100), 2);
            $netSum += $sum;
        }

        $prepaidAmount = null;
        $duePayableAmount = $netSum + $vatSum;
        if ($invoice->getStatus() === InvoiceStatus::PAID->value) {
            $prepaidAmount = $netSum + $vatSum;
            $duePayableAmount = 0.0;
        } // todo collect amount if status is prepaid

        $documentBuilder->setDocumentSummation($netSum + $vatSum, $duePayableAmount, $netSum, 0.0, 0.0, $netSum, $vatSum, null, $prepaidAmount);

        return $documentBuilder->getContent();
    }

    // Maps a VAT rate to its EN 16931 category code. A 0% line (e.g. tourist tax / Kurtaxe) must be
    // "Zero rated" (Z); using "Standard rated" (S) with a 0% rate violates BR-S-05.
    private function resolveVatCategory(float $vat): string
    {
        return $vat > 0 ? ZugferdVatCategoryCodes::STAN_RATE : ZugferdVatCategoryCodes::ZERO_RATE_GOOD;
    }

    private function resolveCurrencyCode(): string
    {
        $settings = $this->appSettingsService->getSettings();
        $codesByConstantName = $this->getCurrencyCodeMap();
        $supportedCodes = array_flip($codesByConstantName);

        $candidates = [
            $settings->getCurrency(),
            $settings->getCurrencySymbol(),
            $this->mapSymbolToIsoCode($settings->getCurrencySymbol()),
        ];

        foreach ($candidates as $candidate) {
            $resolved = $this->resolveCandidateToIsoCode($candidate, $codesByConstantName, $supportedCodes);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return ZugferdCurrencyCodes::EURO;
    }

    /**
     * @return array<string, string> Constant name => ISO code.
     */
    private function getCurrencyCodeMap(): array
    {
        static $map = null;
        if (is_array($map)) {
            return $map;
        }

        /** @var array<string, mixed> $constants */
        $constants = (new \ReflectionClass(ZugferdCurrencyCodes::class))->getConstants();

        $map = array_filter($constants, static fn ($value): bool => is_string($value));

        return $map;
    }

    /**
     * @param array<string, string> $codesByConstantName
     * @param array<string, int>    $supportedCodes
     */
    private function resolveCandidateToIsoCode(mixed $candidate, array $codesByConstantName, array $supportedCodes): ?string
    {
        if (!is_string($candidate)) {
            return null;
        }

        $normalized = strtoupper(trim($candidate));
        if ('' === $normalized) {
            return null;
        }

        if (isset($supportedCodes[$normalized])) {
            return $normalized;
        }

        if (isset($codesByConstantName[$normalized])) {
            return $codesByConstantName[$normalized];
        }

        return null;
    }

    private function mapSymbolToIsoCode(string $symbol): ?string
    {
        return match (trim($symbol)) {
            '€' => ZugferdCurrencyCodes::EURO,
            '$' => ZugferdCurrencyCodes::US_DOLLAR,
            '£' => ZugferdCurrencyCodes::POUND_STERLING,
            default => null,
        };
    }
}
