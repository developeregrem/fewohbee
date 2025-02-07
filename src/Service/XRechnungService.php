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

class XRechnungService
{
    public function __construct(private InvoiceService $is) {}

    public function createInvoice(Invoice $invoice, InvoiceSettingsData $settings): string
    {
        $documentBuilder = ZugferdDocumentBuilder::createNew(ZugferdProfiles::PROFILE_XRECHNUNG_3);

        // General invoice Information

        $documentBuilder->setDocumentInformation(
            $invoice->getNumber(),                                     // Invoice number (BT-1)
            ZugferdInvoiceType::INVOICE,                        // Type "Invoice" (BT-3)
            $invoice->getDate(),      // Invoice fate (BT-2)
            ZugferdCurrencyCodes::EURO,                          // Invoice currency is EUR (Euro) (BT-5)
            'Rechnung-'.$invoice->getNumber(),                   // A document title
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

        $documentBuilder->setDocumentBuyerReference('not used'); // Leitweg-ID, required for B2G communication. Here we set something because its not relevant for B2B or B2C


        // invoice positions
        $pos = 1;
        $vats = [];
        /* @var $apartmentPosition \App\Entity\InvoiceAppartment */
        foreach($invoice->getAppartments() as $apartmentPosition) {
            $documentBuilder->addNewPosition((string)$pos);
            $documentBuilder->setDocumentPositionProductDetails($apartmentPosition->getDescription(), $apartmentPosition->getStartDate()->format('d.m.Y') .' - ' . $apartmentPosition->getEndDate()->format('d.m.Y'));
            $documentBuilder->setDocumentPositionNetPrice($apartmentPosition->getNetPrice());
            $documentBuilder->setDocumentPositionQuantity($apartmentPosition->getAmount(), ZugferdUnitCodes::REC20_PIECE);
            $documentBuilder->addDocumentPositionTax(ZugferdVatCategoryCodes::STAN_RATE, ZugferdVatTypeCodes::VALUE_ADDED_TAX, $apartmentPosition->getVat());
            $documentBuilder->setDocumentPositionLineSummation($apartmentPosition->getNetPrice() * $apartmentPosition->getAmount());
            $pos++;
        }
        
        // invoice misc positions
        /* @var $miscPosition \App\Entity\InvoicePosition */
        foreach($invoice->getPositions() as $miscPosition) {
            $documentBuilder->addNewPosition((string)$pos);
            $documentBuilder->setDocumentPositionProductDetails($miscPosition->getDescription(), '');
            $documentBuilder->setDocumentPositionNetPrice($miscPosition->getNetPrice());
            $documentBuilder->setDocumentPositionQuantity($miscPosition->getAmount(), ZugferdUnitCodes::REC20_PIECE);
            $documentBuilder->addDocumentPositionTax(ZugferdVatCategoryCodes::STAN_RATE, ZugferdVatTypeCodes::VALUE_ADDED_TAX, $miscPosition->getVat());
            $documentBuilder->setDocumentPositionLineSummation($miscPosition->getNetPrice() * $miscPosition->getAmount());
            $pos++;
        }
        $vats = [];
        $gross = 0;
        $net = 0;
        $apartmentTotal = 0;
        $miscTotal = 0;
        $this->is->calculateSums($invoice->getAppartments(), $invoice->getPositions(), $vats, $gross, $net, $apartmentTotal, $miscTotal);

        foreach($vats as $key=>$vat) {
            $documentBuilder->addDocumentTax(ZugferdVatCategoryCodes::STAN_RATE, ZugferdVatTypeCodes::VALUE_ADDED_TAX, $vat['netSum'], $vat['netto'], $key);
        }

        $documentBuilder->setDocumentSummation($gross, $gross, $gross - $net, 0.0, 0.0, $gross - $net, $net);

        return $documentBuilder->getContent();
    }
}
