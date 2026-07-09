<?php

declare(strict_types=1);

namespace App\Tests\Unit\EInvoice;

use App\Entity\Enum\PaymentMeansCode;
use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use App\Service\EInvoice\Validation\En16931InvoiceValidator;
use App\Service\EInvoice\Validation\XRechnungInvoiceValidator;
use PHPUnit\Framework\TestCase;

final class XRechnungInvoiceValidatorTest extends TestCase
{
    private XRechnungInvoiceValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new XRechnungInvoiceValidator(new En16931InvoiceValidator());
    }

    private function buildValidInvoice(): Invoice
    {
        $invoice = new Invoice();
        $invoice->setNumber('R-2026-001');
        $invoice->setDate(new \DateTime('2026-01-15'));
        $invoice->setLastname('Mustermann');
        $invoice->setAddress('Musterweg 1');
        $invoice->setZip('12345');
        $invoice->setCity('Musterstadt');
        $invoice->setCountry('DE');
        $invoice->setEmail('guest@example.com');
        $invoice->setBuyerReference('04011000-1234512345-06');
        $invoice->setPaymentMeans(PaymentMeansCode::SEPA_CREDIT_TRANSFER);

        return $invoice;
    }

    private function buildValidSettings(): InvoiceSettingsData
    {
        $settings = new InvoiceSettingsData();
        $settings->setCompanyName('Hotel Test');
        $settings->setCompanyAddress('Musterweg 1');
        $settings->setCompanyPostCode('12345');
        $settings->setCompanyCity('Musterstadt');
        $settings->setCompanyCountry('DE');
        $settings->setVatID('DE123456789');
        $settings->setPaymentDueDays(14);
        $settings->setAccountIBAN('DE44120300001089790461');
        $settings->setContactName('Max Mustermann');
        $settings->setContactPhone('+49 30 123456');
        $settings->setContactMail('info@hotel-test.de');
        $settings->setCompanyInvoiceMail('rechnung@hotel-test.de');

        return $settings;
    }

    /**
     * @return string[]
     */
    private function violationFields(Invoice $invoice, InvoiceSettingsData $settings): array
    {
        return array_map(
            static fn ($violation) => $violation->field,
            $this->validator->validate($invoice, $settings)->getViolations()
        );
    }

    public function testValidXRechnungInvoicePasses(): void
    {
        self::assertTrue($this->validator->validate($this->buildValidInvoice(), $this->buildValidSettings())->isValid());
    }

    public function testMissingPaymentMeansDetected(): void
    {
        $invoice = $this->buildValidInvoice();
        $invoice->setPaymentMeans(null);

        self::assertContains('paymentMeans', $this->violationFields($invoice, $this->buildValidSettings()));
    }

    public function testMissingBuyerReferenceDetected(): void
    {
        $invoice = $this->buildValidInvoice();
        $invoice->setBuyerReference('   ');

        self::assertContains('buyerReference', $this->violationFields($invoice, $this->buildValidSettings()));
    }

    public function testMissingSellerContactDetected(): void
    {
        $settings = $this->buildValidSettings();
        $settings->setContactPhone(null);

        self::assertContains('sellerContact', $this->violationFields($this->buildValidInvoice(), $settings));
    }

    public function testMissingSellerElectronicAddressDetected(): void
    {
        $settings = $this->buildValidSettings();
        $settings->setCompanyInvoiceMail('');

        self::assertContains('sellerElectronicAddress', $this->violationFields($this->buildValidInvoice(), $settings));
    }

    public function testMissingBuyerEmailDetected(): void
    {
        $invoice = $this->buildValidInvoice();
        $invoice->setEmail(null);

        self::assertContains('buyerEmail', $this->violationFields($invoice, $this->buildValidSettings()));
    }

    public function testDirectDebitRequiresMandateReference(): void
    {
        // BR-DE-29: XRechnung requires the mandate reference for direct debit.
        $invoice = $this->buildValidInvoice();
        $invoice->setPaymentMeans(PaymentMeansCode::SEPA_DIRECT_DEBIT);
        $invoice->setCustomerIBAN('DE89370400440532013000');
        // mandate reference left empty
        $settings = $this->buildValidSettings();
        $settings->setCreditorReference('DE98ZZZ09999999999');

        self::assertContains('mandateReference', $this->violationFields($invoice, $settings));
    }

    public function testBaseViolationsAreMerged(): void
    {
        $invoice = $this->buildValidInvoice();
        $invoice->setCountry(null); // EN 16931 base rule

        $fields = $this->violationFields($invoice, $this->buildValidSettings());

        self::assertContains('buyerCountry', $fields);
    }

    public function testEn16931RulesAreLessStrict(): void
    {
        // The same invoice without buyer reference, contact and payment means is valid
        // for the EN 16931 profile but invalid for XRechnung.
        $invoice = $this->buildValidInvoice();
        $invoice->setBuyerReference(null);
        $invoice->setPaymentMeans(null);
        $invoice->setEmail(null);
        $settings = $this->buildValidSettings();
        $settings->setContactName(null);
        $settings->setContactPhone(null);
        $settings->setContactMail(null);

        self::assertTrue((new En16931InvoiceValidator())->validate($invoice, $settings)->isValid());
        self::assertFalse($this->validator->validate($invoice, $settings)->isValid());
    }
}
