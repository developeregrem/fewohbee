<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\PaymentMeansCode;
use App\Entity\Invoice;
use App\Entity\InvoicePosition;
use App\Entity\InvoiceSettingsData;
use App\Service\AppSettingsService;
use App\Service\EInvoice\EInvoiceExportService;
use horstoeko\zugferd\ZugferdDocumentPdfMerger;
use horstoeko\zugferd\ZugferdDocumentPdfReader;
use horstoeko\zugferd\ZugferdDocumentReader;
use horstoeko\zugferd\ZugferdKositValidator;
use horstoeko\zugferd\ZugferdXsdValidator;
use Mpdf\Mpdf;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('currencySettingsProvider')]
    public function testCurrencyFromAppSettingsIsMappedForZugferd(string $currency, string $currencySymbol, string $expectedIsoCode): void
    {
        $this->updateAppSettingsCurrency($currency, $currencySymbol);

        $settings = $this->createSettingsEntity('xrechnung');
        $invoice = $this->createValidInvoice(PaymentMeansCode::CASH);
        $xml = $this->getExportService()->generateInvoiceData($invoice, $settings);

        self::assertMatchesRegularExpression(
            '/<[^>]*InvoiceCurrencyCode[^>]*>'.preg_quote($expectedIsoCode, '/').'<\/[^>]*InvoiceCurrencyCode>/',
            $xml
        );
    }

    public static function currencySettingsProvider(): array
    {
        return [
            'constant-name-via-symbol' => ['BAD', 'EURO', 'EUR'],
            'iso-code-chf' => ['CHF', 'CHF', 'CHF'],
            'symbol-fallback-euro' => ['', '€', 'EUR'],
            'invalid-fallback-euro' => ['BAD', '???', 'EUR'],
        ];
    }

    public function testArithmeticConsistencyWithIncludesVatTrue(): void
    {
        $settings = $this->createSettingsEntity('xrechnung');
        $invoice = $this->createValidInvoice(PaymentMeansCode::CASH);

        // Add apartment position: 119€ brutto/night × 3 nights, 7% VAT, includesVat=true
        $apartment = new \App\Entity\InvoiceAppartment();
        $apartment->setStartDate(new \DateTime('2026-03-28'));
        $apartment->setEndDate(new \DateTime('2026-03-31'));
        $apartment->setPersons(1);
        $apartment->setBeds(1);
        $apartment->setNumber('1');
        $apartment->setDescription('EZ');
        $apartment->setPrice(119);
        $apartment->setVat(7);
        $apartment->setIncludesVat(true);
        $apartment->setIsFlatPrice(false);
        $apartment->setIsPerRoom(true);
        $invoice->addAppartment($apartment);

        // Add misc position: 10€ brutto, 19% VAT, includesVat=true
        $breakfast = new InvoicePosition();
        $breakfast->setDescription('Frühstück');
        $breakfast->setAmount(1);
        $breakfast->setPrice(10);
        $breakfast->setVat(19);
        $breakfast->setIncludesVat(true);
        $breakfast->setIsFlatPrice(false);
        $breakfast->setIsPerRoom(false);
        $invoice->addPosition($breakfast);

        $xml = $this->getExportService()->generateInvoiceData($invoice, $settings);

        // Parse and verify arithmetic consistency
        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
        $xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');

        // Verify each line: NetPrice × Quantity = LineTotalAmount
        $lineItems = $xpath->query('//ram:IncludedSupplyChainTradeLineItem');
        $lineTotalSum = 0.0;
        foreach ($lineItems as $item) {
            $netPrice = (float) $xpath->evaluate('string(.//ram:NetPriceProductTradePrice/ram:ChargeAmount)', $item);
            $quantity = (float) $xpath->evaluate('string(.//ram:BilledQuantity)', $item);
            $lineTotal = (float) $xpath->evaluate('string(.//ram:LineTotalAmount)', $item);

            self::assertEqualsWithDelta(
                round($netPrice * $quantity, 2),
                $lineTotal,
                0.001,
                sprintf('Line arithmetic mismatch: %.2f × %.2f = %.2f, expected %.2f', $netPrice, $quantity, $netPrice * $quantity, $lineTotal),
            );
            $lineTotalSum += $lineTotal;
        }

        // Verify summation: sum of LineTotalAmounts = TaxBasisTotalAmount
        $taxBasisTotal = (float) $xpath->evaluate('string(//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxBasisTotalAmount)');
        self::assertEqualsWithDelta($lineTotalSum, $taxBasisTotal, 0.001, 'TaxBasisTotalAmount must equal sum of LineTotalAmounts');

        // Verify tax amounts: BasisAmount × Rate / 100 = CalculatedAmount (within rounding)
        $taxEntries = $xpath->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax');
        $taxTotalComputed = 0.0;
        foreach ($taxEntries as $taxEntry) {
            $basisAmount = (float) $xpath->evaluate('string(./ram:BasisAmount)', $taxEntry);
            $rate = (float) $xpath->evaluate('string(./ram:RateApplicablePercent)', $taxEntry);
            $calculatedAmount = (float) $xpath->evaluate('string(./ram:CalculatedAmount)', $taxEntry);

            self::assertEqualsWithDelta(
                round($basisAmount * $rate / 100, 2),
                $calculatedAmount,
                0.001,
                sprintf('Tax arithmetic mismatch: %.2f × %.2f%% = %.2f, expected %.2f', $basisAmount, $rate, $basisAmount * $rate / 100, $calculatedAmount),
            );
            $taxTotalComputed += $calculatedAmount;
        }

        // Verify grand total: TaxBasisTotalAmount + TaxTotalAmount = GrandTotalAmount
        $taxTotal = (float) $xpath->evaluate('string(//ram:TaxTotalAmount)');
        $grandTotal = (float) $xpath->evaluate('string(//ram:GrandTotalAmount)');
        self::assertEqualsWithDelta($taxBasisTotal + $taxTotal, $grandTotal, 0.001, 'GrandTotalAmount must equal TaxBasisTotalAmount + TaxTotalAmount');
        self::assertEqualsWithDelta($taxTotalComputed, $taxTotal, 0.001, 'TaxTotalAmount must equal sum of all CalculatedAmounts');
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

    private function updateAppSettingsCurrency(string $currency, string $currencySymbol): void
    {
        $service = $this->getAppSettingsService();
        $appSettings = $service->getSettings();
        $appSettings->setCurrency($currency);
        $appSettings->setCurrencySymbol($currencySymbol);
        $service->saveSettings($appSettings);
    }

    private function getAppSettingsService(): AppSettingsService
    {
        self::bootKernel();

        return self::getContainer()->get(AppSettingsService::class);
    }
}
