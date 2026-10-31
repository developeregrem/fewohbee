<?php

declare(strict_types=1);

namespace App\Tests\Unit\EInvoice;

use App\Entity\AppSettings;
use App\Entity\Enum\PaymentMeansCode;
use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\InvoicePosition;
use App\Entity\InvoiceSettingsData;
use App\Service\AppSettingsService;
use App\Service\EInvoice\Validation\En16931InvoiceValidator;
use App\Service\EInvoice\ZugferdInvoiceGenerator;
use horstoeko\zugferd\ZugferdProfiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ZugferdInvoiceTaxGroupingTest extends TestCase
{
    #[DataProvider('vatRates')]
    public function testXmlKeepsTaxRatesSeparateAndCombinesTheSameRateAcrossPositionTypes(
        float $stayVat,
        float $miscVat,
    ): void {
        $invoice = new Invoice();
        $invoice->setNumber('TAX-001');
        $invoice->setDate(new \DateTime('2031-06-02'));
        $invoice->setLastname('Example');
        $invoice->setAddress('Example Street 1');
        $invoice->setZip('01234');
        $invoice->setCity('Example City');
        $invoice->setCountry('DE');
        $invoice->setPaymentMeans(PaymentMeansCode::CASH);

        $stay = new InvoiceAppartment();
        $stay->setDescription('Room');
        $stay->setStartDate(new \DateTime('2031-06-01'));
        $stay->setEndDate(new \DateTime('2031-06-02'));
        $stay->setPrice('100.00');
        $stay->setVat($stayVat);
        $stay->setIncludesVat(false);
        $stay->setIsFlatPrice(false);
        $stay->setIsPerRoom(true);
        $invoice->addAppartment($stay);
        foreach ([$miscVat, $stayVat] as $vat) {
            $position = new InvoicePosition();
            $position->setDescription('Service');
            $position->setAmount(1);
            $position->setPrice('100.00');
            $position->setVat($vat);
            $position->setIncludesVat(false);
            $position->setIsFlatPrice(false);
            $invoice->addPosition($position);
        }

        $settings = new InvoiceSettingsData();
        $settings->setCompanyName('Example Hotel');
        $settings->setCompanyAddress('Example Street 2');
        $settings->setCompanyPostCode('01234');
        $settings->setCompanyCity('Example City');
        $settings->setCompanyCountry('DE');
        $settings->setVatID('DE123456789');
        $settings->setPaymentDueDays(14);
        $appSettings = $this->createStub(AppSettingsService::class);
        $appSettings->method('getSettings')->willReturn(new AppSettings());
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $generator = new ZugferdInvoiceGenerator($translator, $appSettings);

        $xml = $generator->generateInvoiceData($invoice, $settings, ZugferdProfiles::PROFILE_EN16931, new En16931InvoiceValidator());

        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml));
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
        $taxes = $xpath->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax');
        self::assertNotFalse($taxes);
        self::assertCount(2, $taxes);
        $actual = [];
        foreach ($taxes as $tax) {
            $rate = $xpath->evaluate('string(ram:RateApplicablePercent)', $tax);
            $actual[$rate] = [
                'basis' => (float) $xpath->evaluate('string(ram:BasisAmount)', $tax),
                'tax' => (float) $xpath->evaluate('string(ram:CalculatedAmount)', $tax),
                'category' => $xpath->evaluate('string(ram:CategoryCode)', $tax),
            ];
        }
        self::assertSame([
            number_format($stayVat, 2, '.', '') => [
                'basis' => 200.0,
                'tax' => round(2 * $stayVat, 2),
                'category' => $stayVat > 0 ? 'S' : 'Z',
            ],
            number_format($miscVat, 2, '.', '') => [
                'basis' => 100.0,
                'tax' => $miscVat,
                'category' => $miscVat > 0 ? 'S' : 'Z',
            ],
        ], $actual);
        $taxTotal = round(2 * $stayVat, 2) + $miscVat;
        self::assertSame(300.0, (float) $xpath->evaluate('string(//ram:TaxBasisTotalAmount)'));
        self::assertSame($taxTotal, (float) $xpath->evaluate('string(//ram:TaxTotalAmount)'));
        self::assertSame(300.0 + $taxTotal, (float) $xpath->evaluate('string(//ram:GrandTotalAmount)'));
        self::assertSame(300.0 + $taxTotal, (float) $xpath->evaluate('string(//ram:DuePayableAmount)'));
    }

    /** @return iterable<string, array{float, float}> */
    public static function vatRates(): iterable
    {
        yield 'whole rates' => [7.0, 19.0];
        yield 'whole and fractional' => [7.0, 7.5];
        yield 'fractional rates with the same integer part' => [7.5, 7.75];
        yield 'rates below one percent' => [0.5, 0.75];
        yield 'zero and fractional' => [0.0, 7.5];
    }
}
