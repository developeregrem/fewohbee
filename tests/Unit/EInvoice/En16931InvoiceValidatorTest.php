<?php

declare(strict_types=1);

namespace App\Tests\Unit\EInvoice;

use App\Entity\Enum\PaymentMeansCode;
use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use App\Service\EInvoice\Validation\En16931InvoiceValidator;
use PHPUnit\Framework\TestCase;

final class En16931InvoiceValidatorTest extends TestCase
{
    private En16931InvoiceValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new En16931InvoiceValidator();
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

    public function testValidInvoicePasses(): void
    {
        $result = $this->validator->validate($this->buildValidInvoice(), $this->buildValidSettings());

        self::assertTrue($result->isValid());
        self::assertSame([], $result->getViolations());
    }

    public function testMissingBuyerNameDetected(): void
    {
        $invoice = $this->buildValidInvoice();
        $invoice->setLastname(null);

        self::assertContains('buyerName', $this->violationFields($invoice, $this->buildValidSettings()));
    }

    public function testCompanyAloneSatisfiesBuyerName(): void
    {
        $invoice = $this->buildValidInvoice();
        $invoice->setLastname(null);
        $invoice->setCompany('Firma GmbH');

        self::assertNotContains('buyerName', $this->violationFields($invoice, $this->buildValidSettings()));
    }

    public function testMissingBuyerCountryDetected(): void
    {
        $invoice = $this->buildValidInvoice();
        $invoice->setCountry(null);

        self::assertContains('buyerCountry', $this->violationFields($invoice, $this->buildValidSettings()));
    }

    public function testMissingZipOrCityDetected(): void
    {
        $invoice = $this->buildValidInvoice();
        $invoice->setZip(null);

        self::assertContains('buyerPostCodeCity', $this->violationFields($invoice, $this->buildValidSettings()));
    }

    public function testMissingInvoiceNumberDetected(): void
    {
        $invoice = $this->buildValidInvoice();
        $invoice->setNumber('');

        self::assertContains('invoiceNumber', $this->violationFields($invoice, $this->buildValidSettings()));
    }

    public function testMissingInvoiceDateDetected(): void
    {
        // a fresh invoice has no date set; getDate() would throw, the validator must handle it
        $invoice = $this->buildValidInvoice();
        $reflection = new \ReflectionProperty(\App\Entity\Invoice::class, 'date');
        $reflection->setValue($invoice, null);

        self::assertContains('invoiceDate', $this->violationFields($invoice, $this->buildValidSettings()));
    }

    public function testMissingBuyerStreetDetected(): void
    {
        $invoice = $this->buildValidInvoice();
        $invoice->setAddress(null);

        self::assertContains('buyerStreet', $this->violationFields($invoice, $this->buildValidSettings()));
    }

    public function testMissingSellerNameDetected(): void
    {
        $settings = $this->buildValidSettings();
        $settings->setCompanyName('');

        self::assertContains('sellerName', $this->violationFields($this->buildValidInvoice(), $settings));
    }

    public function testIncompleteSellerAddressDetected(): void
    {
        $settings = $this->buildValidSettings();
        $settings->setCompanyCity('');

        self::assertContains('sellerAddress', $this->violationFields($this->buildValidInvoice(), $settings));
    }

    public function testMissingSellerIdentifierDetected(): void
    {
        $settings = $this->buildValidSettings();
        $settings->setVatID(null);
        $settings->setRegistrationNumber(null);

        self::assertContains('sellerIdentifier', $this->violationFields($this->buildValidInvoice(), $settings));
    }

    public function testTaxNumberAloneDoesNotSatisfyBrCo26(): void
    {
        // BR-CO-26: the German tax number (BT-32) is not an accepted seller identifier.
        $settings = $this->buildValidSettings();
        $settings->setVatID(null);
        $settings->setRegistrationNumber(null);
        $settings->setTaxNumber('12/345/67890');

        self::assertContains('sellerIdentifier', $this->violationFields($this->buildValidInvoice(), $settings));
    }

    public function testRegistrationNumberSatisfiesBrCo26(): void
    {
        // A legal registration number (BT-30) substitutes for the VAT id (BT-31).
        $settings = $this->buildValidSettings();
        $settings->setVatID(null);
        $settings->setRegistrationNumber('HRB 12345');

        self::assertNotContains('sellerIdentifier', $this->violationFields($this->buildValidInvoice(), $settings));
    }

    public function testMissingPaymentTermsDetected(): void
    {
        $settings = $this->buildValidSettings();
        $settings->setPaymentDueDays(null);
        $settings->setPaymentTerms(null);

        self::assertContains('paymentTerms', $this->violationFields($this->buildValidInvoice(), $settings));
    }

    public function testNoPaymentMeansCausesNoPaymentViolations(): void
    {
        $settings = $this->buildValidSettings();
        $settings->setAccountIBAN('');

        // payment means is optional for EN 16931; without it no payment-specific rule applies
        self::assertTrue($this->validator->validate($this->buildValidInvoice(), $settings)->isValid());
    }

    public function testSepaCreditTransferRequiresIban(): void
    {
        $invoice = $this->buildValidInvoice();
        $invoice->setPaymentMeans(PaymentMeansCode::SEPA_CREDIT_TRANSFER);
        $settings = $this->buildValidSettings();
        $settings->setAccountIBAN('');

        self::assertContains('accountIBAN', $this->violationFields($invoice, $settings));
    }

    public function testCreditTransferRequiresIbanAndBic(): void
    {
        $invoice = $this->buildValidInvoice();
        $invoice->setPaymentMeans(PaymentMeansCode::CREDIT_TRANSFER);
        $settings = $this->buildValidSettings(); // BIC not set

        self::assertContains('accountIBANBIC', $this->violationFields($invoice, $settings));
    }

    public function testCardPaymentRequiresCardNumber(): void
    {
        $invoice = $this->buildValidInvoice();
        $invoice->setPaymentMeans(PaymentMeansCode::CARD_PAYMENT);

        self::assertContains('cardNumber', $this->violationFields($invoice, $this->buildValidSettings()));
    }

    public function testDirectDebitRequiresCustomerIbanAndCreditorReference(): void
    {
        $invoice = $this->buildValidInvoice();
        $invoice->setPaymentMeans(PaymentMeansCode::SEPA_DIRECT_DEBIT);

        $fields = $this->violationFields($invoice, $this->buildValidSettings());

        self::assertContains('customerIBAN', $fields);
        self::assertContains('creditorReference', $fields);
    }

    public function testDirectDebitDoesNotRequireMandateReferenceForEn16931(): void
    {
        // BR-DE-29 (mandate reference) is XRechnung-only; ZUGFeRD/EN 16931 accepts direct debit
        // with just the customer IBAN and creditor reference.
        $invoice = $this->buildValidInvoice();
        $invoice->setPaymentMeans(PaymentMeansCode::SEPA_DIRECT_DEBIT);
        $invoice->setCustomerIBAN('DE89370400440532013000');
        // mandate reference deliberately left empty
        $settings = $this->buildValidSettings();
        $settings->setCreditorReference('DE98ZZZ09999999999');

        self::assertTrue($this->validator->validate($invoice, $settings)->isValid());
    }

    public function testCollectsAllViolationsAtOnce(): void
    {
        $invoice = new Invoice();
        $settings = new InvoiceSettingsData();

        $result = $this->validator->validate($invoice, $settings);

        self::assertFalse($result->isValid());
        self::assertGreaterThanOrEqual(4, count($result->getViolations()));
    }
}
