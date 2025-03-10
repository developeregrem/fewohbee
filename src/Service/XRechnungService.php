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
use horstoeko\zugferd\codelists\ZugferdCountryCodes;
use horstoeko\zugferd\codelists\ZugferdCurrencyCodes;
use horstoeko\zugferd\codelists\ZugferdElectronicAddressScheme;
use horstoeko\zugferd\codelists\ZugferdInvoiceType;
use horstoeko\zugferd\codelists\ZugferdReferenceCodeQualifiers;
use horstoeko\zugferd\codelists\ZugferdUnitCodes;
use horstoeko\zugferd\codelists\ZugferdVatCategoryCodes;
use horstoeko\zugferd\codelists\ZugferdVatTypeCodes;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use Symfony\Contracts\Translation\TranslatorInterface;
use InvalidArgumentException;

class XRechnungService
{
    public function __construct(private InvoiceService $is, private TranslatorInterface $translator) {}

    public function createInvoice(Invoice $invoice, InvoiceSettingsData $settings): string
    {
        if (empty($invoice->getPhone()) || empty($invoice->getEmail()) || empty($invoice->getCountry())) {
            throw new InvalidArgumentException($this->translator->trans('invoice.xrechnung.mandatory.error'));
        }

        $documentBuilder = ZugferdDocumentBuilder::createNew(ZugferdProfiles::PROFILE_XRECHNUNG_3);

        // General invoice Information

        $documentBuilder->setDocumentInformation(
            $invoice->getNumber(),                                     // Invoice number (BT-1)
            ZugferdInvoiceType::INVOICE,                        // Type "Invoice" (BT-3)
            $invoice->getDate(),      // Invoice fate (BT-2)
            ZugferdCurrencyCodes::EURO,                          // Invoice currency is EUR (Euro) (BT-5)
            $this->translator->trans('invoice.number.short').'-'.$invoice->getNumber(),                   // A document title
        );

        // seller information
        $documentBuilder->setDocumentSeller($settings->getCompanyName()); // company name of the hotel
        $documentBuilder->addDocumentSellerTaxNumber($settings->getTaxNumber());  // Tax number
        $documentBuilder->addDocumentSellerVATRegistrationNumber($settings->getVatID()); // Umsatzsteuer-Identifikationsnummer / VAT ID, not always available
        $documentBuilder->setDocumentSellerAddress($settings->getCompanyAddress(), '', '', $settings->getCompanyPostCode(), $settings->getCompanyCity(), $settings->getCompanyCountry());
        $documentBuilder->setDocumentSellerContact($settings->getContactName(), $settings->getContactDepartment(), $settings->getContactPhone(), null, $settings->getContactMail());
        $documentBuilder->setDocumentSellerCommunication(ZugferdElectronicAddressScheme::UNECE3155_EM, $settings->getCompanyInvoiceMail());

        // customer information
        $customerName = $invoice->getSalutation() . ' ' . $invoice->getFirstname() . ' ' . $invoice->getLastname();
        $documentBuilder->setDocumentBuyer((!empty($invoice->getCompany()) ? $invoice->getCompany() : $customerName));
        $documentBuilder->setDocumentBuyerAddress($invoice->getAddress(), '', '', $invoice->getZip(), $invoice->getCity(), $invoice->getCountry()); // todo ländercode
        $documentBuilder->setDocumentBuyerContact($customerName, null, $invoice->getPhone(), null, $invoice->getEmail()); // todo telefon, mail
        $documentBuilder->setDocumentBuyerCommunication(ZugferdElectronicAddressScheme::UNECE3155_EM, $invoice->getEmail());

        $documentBuilder->addDocumentPaymentMeanToCreditTransfer($settings->getAccountIBAN(), $settings->getAccountName(), null, $settings->getAccountBIC()); // Payment information
        
        // payment terms and due date
        $dueDate = (!is_null($settings->getPaymentDueDays()) ? $invoice->getDate()->modify('+' . $settings->getPaymentDueDays() . ' days') : null);
        $documentBuilder->addDocumentPaymentTerm($settings->getPaymentTerms(), $dueDate); // Payment term

        $documentBuilder->setDocumentBuyerReference($invoice->getBuyerReference() !== null ? $invoice->getBuyerReference() : 'not used'); // Leitweg-ID, required for B2G communication. Here we set something because its not relevant for B2B or B2C


        // invoice positions
        $pos = 1;
        $netSums = [];
        /* @var $apartmentPosition \App\Entity\InvoiceAppartment */
        foreach($invoice->getAppartments() as $apartmentPosition) {
            $sum = round($apartmentPosition->getNetPrice() * $apartmentPosition->getAmount(), 2);
            $documentBuilder->addNewPosition((string)$pos);
            $documentBuilder->setDocumentPositionProductDetails($apartmentPosition->getDescription(), $apartmentPosition->getStartDate()->format('d.m.Y') .' - ' . $apartmentPosition->getEndDate()->format('d.m.Y'));
            $documentBuilder->setDocumentPositionNetPrice($apartmentPosition->getNetPrice());
            $documentBuilder->setDocumentPositionQuantity($apartmentPosition->getAmount(), ZugferdUnitCodes::REC20_PIECE);
            $documentBuilder->addDocumentPositionTax(ZugferdVatCategoryCodes::STAN_RATE, ZugferdVatTypeCodes::VALUE_ADDED_TAX, $apartmentPosition->getVat());
            $documentBuilder->setDocumentPositionLineSummation($sum);
            $netSums[$apartmentPosition->getVat()] = ($netSums[$apartmentPosition->getVat()] ?? 0) + $sum;
            $pos++;
        }
        
        // invoice misc positions
        /* @var $miscPosition \App\Entity\InvoicePosition */
        foreach($invoice->getPositions() as $miscPosition) {
            $sum = round($miscPosition->getNetPrice() * $miscPosition->getAmount(), 2);
            $documentBuilder->addNewPosition((string)$pos);
            $documentBuilder->setDocumentPositionProductDetails($miscPosition->getDescription(), '');
            $documentBuilder->setDocumentPositionNetPrice($miscPosition->getNetPrice());
            $documentBuilder->setDocumentPositionQuantity($miscPosition->getAmount(), ZugferdUnitCodes::REC20_PIECE);
            $documentBuilder->addDocumentPositionTax(ZugferdVatCategoryCodes::STAN_RATE, ZugferdVatTypeCodes::VALUE_ADDED_TAX, $miscPosition->getVat());
            $documentBuilder->setDocumentPositionLineSummation($sum);
            $netSums[$miscPosition->getVat()] = ($netSums[$miscPosition->getVat()] ?? 0) + $sum;
            $pos++;
        }
        
        $vatSum = 0;
        $netSum = 0;
        foreach($netSums as $vat=>$sum) {
            $documentBuilder->addDocumentTax(ZugferdVatCategoryCodes::STAN_RATE, ZugferdVatTypeCodes::VALUE_ADDED_TAX, $sum, $sum*($vat/100), $vat);
            $vatSum += round($sum*($vat/100), 2);
            $netSum += $sum;
        }

        $documentBuilder->setDocumentSummation($netSum + $vatSum, $netSum + $vatSum, $netSum, 0.0, 0.0, $netSum, $vatSum);

        return $documentBuilder->getContent();
    }
}
