<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\PaymentMeansCode;
use App\Entity\Invoice;
use App\Entity\InvoicePosition;
use App\Entity\InvoiceSettingsData;
use App\Service\EInvoice\EInvoiceExportService;
use horstoeko\zugferd\ZugferdDocumentPdfMerger;
use horstoeko\zugferd\ZugferdDocumentPdfReader;
use horstoeko\zugferd\ZugferdDocumentReader;
use horstoeko\zugferd\ZugferdKositValidator;
use horstoeko\zugferd\ZugferdXsdValidator;
use Mpdf\Mpdf;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EInvoiceExportTest extends KernelTestCase
{
    public function testMandatoryFieldValidations(): void
    {
        $cases = [
            'missing_country' => [
                'expected' => 'invoice.xrechnung.mandatory.buyerCountry',
                'paymentMeans' => PaymentMeansCode::CASH,
                'mutateInvoice' => static function (Invoice $invoice): void {
                    $invoice->setCountry(null);
                },
                'mutateSettings' => null,
            ],
            'missing_zip_or_city' => [
                'expected' => 'invoice.xrechnung.mandatory.buyerPostCodeCity',
                'paymentMeans' => PaymentMeansCode::CASH,
                'mutateInvoice' => static function (Invoice $invoice): void {
                    $invoice->setZip(null);
                },
                'mutateSettings' => null,
            ],
            'missing_payment_means' => [
                'expected' => 'invoice.xrechnung.mandatory.paymentMeans',
                'paymentMeans' => null,
                'mutateInvoice' => null,
                'mutateSettings' => null,
            ],
            'missing_payment_terms_and_due_days' => [
                'expected' => 'invoice.settings.paymentterm.error',
                'paymentMeans' => PaymentMeansCode::CASH,
                'mutateInvoice' => null,
                'mutateSettings' => static function (InvoiceSettingsData $settings): void {
                    $settings->setPaymentDueDays(null);
                    $settings->setPaymentTerms(null);
                },
            ],
            'missing_iban' => [
                'expected' => 'invoice.xrechnung.mandatory.IBAN',
                'paymentMeans' => PaymentMeansCode::SEPA_CREDIT_TRANSFER,
                'mutateInvoice' => null,
                'mutateSettings' => static function (InvoiceSettingsData $settings): void {
                    $settings->setAccountIBAN('');
                },
            ],
            'missing_iban_bic' => [
                'expected' => 'invoice.xrechnung.mandatory.IBAN_BIC',
                'paymentMeans' => PaymentMeansCode::CREDIT_TRANSFER,
                'mutateInvoice' => null,
                'mutateSettings' => static function (InvoiceSettingsData $settings): void {
                    $settings->setAccountBIC(null);
                },
            ],
            'missing_card_number' => [
                'expected' => 'invoice.xrechnung.mandatory.cardNumber',
                'paymentMeans' => PaymentMeansCode::CARD_PAYMENT,
                'mutateInvoice' => static function (Invoice $invoice): void {
                    $invoice->setCardNumber(null);
                },
                'mutateSettings' => null,
            ],
            'missing_direct_debit_iban' => [
                'expected' => 'invoice.xrechnung.mandatory.IBANBuyer',
                'paymentMeans' => PaymentMeansCode::SEPA_DIRECT_DEBIT,
                'mutateInvoice' => static function (Invoice $invoice): void {
                    $invoice->setCustomerIBAN(null);
                },
                'mutateSettings' => null,
            ],
            'missing_creditor_reference' => [
                'expected' => 'invoice.xrechnung.mandatory.creditorReference',
                'paymentMeans' => PaymentMeansCode::SEPA_DIRECT_DEBIT,
                'mutateInvoice' => null,
                'mutateSettings' => static function (InvoiceSettingsData $settings): void {
                    $settings->setCreditorReference(null);
                },
            ],
        ];

        foreach ($cases as $case) {
            $invoice = $this->createValidInvoice($case['paymentMeans']);
            $settings = $this->createSettingsEntity('xrechnung');
            if ($case['mutateInvoice']) {
                $case['mutateInvoice']($invoice);
            }
            if ($case['mutateSettings']) {
                $case['mutateSettings']($settings);
            }

            try {
                $this->getExportService()->generateInvoiceData($invoice, $settings);
                self::fail('Expected exception for '.$case['expected']);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($case['expected'], $exception->getMessage());
            }
        }
    }

    public function testXmlIsXsdValidForProfiles(): void
    {
        foreach (['xrechnung', 'en16931'] as $profile) {
            $settings = $this->createSettingsEntity($profile);
            $invoice = $this->createValidInvoice(PaymentMeansCode::CASH);
            $xml = $this->getExportService()->generateInvoiceData($invoice, $settings);

            $document = ZugferdDocumentReader::readAndGuessFromContent($xml);
            $validator = new ZugferdXsdValidator($document);
            $validator->validate();

            self::assertTrue(
                $validator->hasNoValidationErrors(),
                'XSD validation failed for profile '.$profile.': '.implode("\n", $validator->validationErrors())
            );
        }
    }

    public function testXmlPassesKositValidation(): void
    {
        $settings = $this->createSettingsEntity('xrechnung');
        $invoice = $this->createValidInvoice(PaymentMeansCode::CASH);
        $xml = $this->getExportService()->generateInvoiceData($invoice, $settings);

        $validator = ZugferdKositValidator::fromString($xml);
        $validator->validate();

        if ($validator->hasProcessErrors()) {
            self::markTestSkipped('Kosit validator unavailable: '.implode("\n", $validator->getProcessErrors()));
        }

        self::assertTrue(
            $validator->hasNoValidationErrors(),
            'Kosit validation failed: '.implode("\n", $validator->getValidationErrors())
        );
    }

    public function testPdfWithEmbeddedXmlIsReadable(): void
    {
        $settings = $this->createSettingsEntity('xrechnung');
        $invoice = $this->createValidInvoice(PaymentMeansCode::CASH);
        $xml = $this->getExportService()->generateInvoiceData($invoice, $settings);

        $mpdf = new Mpdf();
        $pdfContent = $mpdf->Output('', 'S');
        $mergedPdf = (new ZugferdDocumentPdfMerger($xml, $pdfContent))
            ->generateDocument()
            ->downloadString();

        $embeddedXml = ZugferdDocumentPdfReader::getXmlFromContent($mergedPdf);

        self::assertNotSame('', $mergedPdf);
        self::assertNotSame('', $embeddedXml);
        self::assertStringContainsString($invoice->getNumber(), $embeddedXml);
    }

    public function testAllPaymentMeansGenerateValidXml(): void
    {
        $settings = $this->createSettingsEntity('xrechnung');
        foreach (PaymentMeansCode::cases() as $paymentMeans) {
            $invoice = $this->createValidInvoice($paymentMeans);
            $xml = $this->getExportService()->generateInvoiceData($invoice, $settings);

            $document = ZugferdDocumentReader::readAndGuessFromContent($xml);
            $validator = new ZugferdXsdValidator($document);
            $validator->validate();

            self::assertTrue(
                $validator->hasNoValidationErrors(),
                'XSD validation failed for payment means '.$paymentMeans->name.': '.implode("\n", $validator->validationErrors())
            );
        }
    }

    // Creates a valid invoice with required base fields populated.
    private function createValidInvoice(?PaymentMeansCode $paymentMeans): Invoice
    {
        $invoice = new Invoice();
        $invoice->setNumber('INV-1000');
        $invoice->setDate(new \DateTime('2024-01-15'));
        $invoice->setSalutation('Herr');
        $invoice->setFirstname('Max');
        $invoice->setLastname('Mustermann');
        $invoice->setAddress('Musterstraße 1');
        $invoice->setZip('12345');
        $invoice->setCity('Musterhausen');
        $invoice->setCountry('DE');
        $invoice->setEmail('max@mustermann.de');
        $invoice->setPhone('0123456789');
        $invoice->setPaymentMeans($paymentMeans);

        if ($paymentMeans === PaymentMeansCode::CARD_PAYMENT) {
            $invoice->setCardNumber('4111111111111111');
            $invoice->setCardHolder('Max Mustermann');
        }

        if ($paymentMeans === PaymentMeansCode::SEPA_DIRECT_DEBIT) {
            $invoice->setCustomerIBAN('DE89370400440532013000');
            $invoice->setMandateReference('MR-123');
        }

        $position = new InvoicePosition();
        $position->setDescription('Testleistung');
        $position->setAmount(1);
        $position->setPrice('100.00');
        $position->setVat(19);
        $invoice->addPosition($position);

        return $invoice;
    }

    // Creates and persists settings using representative valid data.
    private function createSettingsEntity(string $profile): InvoiceSettingsData
    {
        $settings = new InvoiceSettingsData();
        $settings->setCompanyName('Mein Testhotel');
        $settings->setTaxNumber('201/113/40209');
        $settings->setVatID('DE123456789');
        $settings->setContactName('Max Mustermann');
        $settings->setContactDepartment('Buchhaltung');
        $settings->setContactPhone('0123456789');
        $settings->setContactMail('max@mustermann.de');
        $settings->setCompanyInvoiceMail('rechnung@mustermann.de');
        $settings->setCompanyAddress('Musterstraße 1');
        $settings->setCompanyPostCode('12345');
        $settings->setCompanyCity('Musterhausen');
        $settings->setCompanyCountry('DE');
        $settings->setAccountIBAN('GB33BUKB20201555555555');
        $settings->setAccountName('Max Mustermann');
        $settings->setAccountBIC('DRESDEFFXXX');
        $settings->setPaymentTerms('Zahlbar innerhalb von 30 Tagen auf das angegebene Konto.');
        $settings->setIsActive(true);
        $settings->setPaymentDueDays(30);
        $settings->setCreditorReference('DE98ZZZ09999999999');
        $settings->setEinvoiceProfile($profile);

        $entityManager = $this->getEntityManager();
        $entityManager->persist($settings);
        $entityManager->flush();

        return $settings;
    }

    // Returns the export service from the container.
    private function getExportService(): EInvoiceExportService
    {
        self::bootKernel();

        return self::getContainer()->get(EInvoiceExportService::class);
    }

    // Returns the entity manager from the container.
    private function getEntityManager(): \Doctrine\ORM\EntityManagerInterface
    {
        self::bootKernel();

        return self::getContainer()->get('doctrine')->getManager();
    }
}
