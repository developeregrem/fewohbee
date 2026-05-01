<?php

declare(strict_types=1);

namespace App\Tests\Unit\BookingJournal\BankImport;

use App\Dto\BookingJournal\BankImport\ImportState;
use App\Entity\AccountingSettings;
use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\InvoicePosition;
use App\Repository\InvoiceRepository;
use App\Service\BookingJournal\AccountingSettingsService;
use App\Service\BookingJournal\BankImport\InvoiceMatcher;
use App\Service\BookingJournal\BankImport\InvoiceNumberPatternBuilder;
use App\Service\InvoiceService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class InvoiceMatcherTest extends TestCase
{
    public function testReturnsNullWhenNoCandidatesFound(): void
    {
        $matcher = $this->createMatcher(['RE-12345'], []);

        $result = $matcher->matchPurpose('Beliebiger Verwendungszweck ohne Treffer', '-99.00', $this->buildPattern(['RE-12345']));

        self::assertNull($result);
    }

    public function testReturnsSingleCandidateWhenInvoiceExists(): void
    {
        $invoice = $this->createInvoice('RE-12345');
        $matcher = $this->createMatcher(['RE-12345'], [$invoice]);

        $result = $matcher->matchPurpose('Zahlung Rechnung RE-12345 vielen Dank', '-100.00', $this->buildPattern(['RE-12345']));

        self::assertSame($invoice, $result);
    }

    public function testTiebreaksMultipleCandidatesByAmount(): void
    {
        // Two invoices both detected from purpose; only one matches the amount.
        $invoiceA = $this->createInvoice('RE-12345', appartmentBrutto: 50.0);
        $invoiceB = $this->createInvoice('RE-12399', appartmentBrutto: 100.0);

        $matcher = $this->createMatcher(['RE-12345'], [$invoiceA, $invoiceB]);

        $result = $matcher->matchPurpose('Sammel RE-12345 und RE-12399', '-100.00', $this->buildPattern(['RE-12345']));

        self::assertSame($invoiceB, $result);
    }

    public function testFallsBackToFirstCandidateWhenNoAmountMatches(): void
    {
        $invoiceA = $this->createInvoice('RE-12345', appartmentBrutto: 30.0);
        $invoiceB = $this->createInvoice('RE-12399', appartmentBrutto: 40.0);

        $matcher = $this->createMatcher(['RE-12345'], [$invoiceA, $invoiceB]);

        // Neither invoice's brutto (30 / 40) matches the line amount (99).
        $result = $matcher->matchPurpose('Sammel RE-12345 und RE-12399', '-99.00', $this->buildPattern(['RE-12345']));

        self::assertSame($invoiceA, $result, 'Without amount match, the first textual candidate wins.');
    }

    public function testIncomingAmountMatchesByAbsoluteValue(): void
    {
        $invoice = $this->createInvoice('RE-12345', appartmentBrutto: 250.0);
        $matcher = $this->createMatcher(['RE-12345'], [$invoice]);

        $result = $matcher->matchPurpose('Eingang Rechnung RE-12345', '250.00', $this->buildPattern(['RE-12345']));

        self::assertSame($invoice, $result);
    }

    public function testAnnotateMarksExactIncomingInvoiceMatchReady(): void
    {
        $invoice = $this->createInvoice('RE-12345', appartmentBrutto: 250.0);
        $matcher = $this->createMatcher(['RE-12345'], [$invoice]);
        $state = $this->createState('Zahlung Rechnung RE-12345', '250.00');
        $state->lines[0]['userDebitAccountId'] = 12;

        $matcher->annotate($state);

        self::assertSame($invoice->getId(), $state->lines[0]['matchedInvoiceId']);
        self::assertSame('RE-12345', $state->lines[0]['matchedInvoiceNumber']);
        self::assertTrue($state->lines[0]['matchedInvoiceAmountMatches']);
        self::assertSame(ImportState::LINE_STATUS_READY, $state->lines[0]['status']);
    }

    public function testAnnotateKeepsAmountMismatchPending(): void
    {
        $invoice = $this->createInvoice('RE-12345', appartmentBrutto: 250.0);
        $matcher = $this->createMatcher(['RE-12345'], [$invoice]);
        $state = $this->createState('Zahlung Rechnung RE-12345', '200.00');
        $state->lines[0]['userDebitAccountId'] = 12;

        $matcher->annotate($state);

        self::assertSame($invoice->getId(), $state->lines[0]['matchedInvoiceId']);
        self::assertFalse($state->lines[0]['matchedInvoiceAmountMatches']);
        self::assertSame(ImportState::LINE_STATUS_PENDING, $state->lines[0]['status']);
    }

    // ── Test helpers ─────────────────────────────────────────────────

    private function buildPattern(array $samples): \App\Service\BookingJournal\BankImport\CompiledMatcher
    {
        return (new InvoiceNumberPatternBuilder())->buildFromSamples($samples);
    }

    /**
     * @param list<string>  $samples
     * @param list<Invoice> $existingInvoices
     */
    private function createMatcher(array $samples, array $existingInvoices): InvoiceMatcher
    {
        $settings = new AccountingSettings();
        $settings->setInvoiceNumberSamples($samples);

        $settingsService = $this->createMock(AccountingSettingsService::class);
        $settingsService->method('getSettings')->willReturn($settings);

        $invoiceRepo = $this->createMock(InvoiceRepository::class);
        $invoiceRepo->method('findByNumbers')->willReturnCallback(
            static function (array $numbers) use ($existingInvoices): array {
                return array_values(array_filter(
                    $existingInvoices,
                    static fn (Invoice $i) => in_array($i->getNumber(), $numbers, true),
                ));
            },
        );

        // InvoiceService has heavy DI; we only need calculateSums, so a stub
        // that mirrors the existing brutto-from-flat-price logic is enough.
        $invoiceService = $this->createMock(InvoiceService::class);
        $invoiceService->method('calculateSums')->willReturnCallback(
            function ($apps, $poss, &$vats, &$brutto, &$netto, &$apartmentTotal, &$miscTotal): void {
                $brutto = 0.0;
                foreach ($apps as $a) {
                    $brutto += (float) $a->getPrice();
                }
                foreach ($poss as $p) {
                    $brutto += (float) $p->getPrice();
                }
            },
        );

        return new InvoiceMatcher(
            $settingsService,
            new InvoiceNumberPatternBuilder(),
            $invoiceRepo,
            $invoiceService,
        );
    }

    private function createInvoice(string $number, float $appartmentBrutto = 100.0): Invoice
    {
        $invoice = new Invoice();
        (new \ReflectionProperty(Invoice::class, 'id'))->setValue($invoice, crc32($number));
        $invoice->setNumber($number);
        $invoice->setDate(new \DateTime('2026-03-15'));
        $invoice->setStatus(1);

        $appartment = new InvoiceAppartment();
        $appartment->setIsFlatPrice(true);
        $appartment->setPrice($appartmentBrutto);
        $appartment->setVat(0.0);
        $appartment->setIncludesVat(true);

        $invoice->setAppartments(new ArrayCollection([$appartment]));
        $invoice->setPositions(new ArrayCollection());

        return $invoice;
    }

    private function createState(string $purpose, string $amount): ImportState
    {
        $state = new ImportState(
            sessionImportId: 'test',
            bankAccountId: 1,
            fileFormat: 'csv_generic',
            bankCsvProfileId: 1,
            originalFilename: 'test.csv',
            sourceIban: null,
            periodFrom: null,
            periodTo: null,
            createdAt: new \DateTimeImmutable(),
        );

        $state->lines[] = [
            'idx' => 0,
            'bookDate' => '2026-03-15',
            'valueDate' => '2026-03-15',
            'amount' => $amount,
            'counterpartyName' => '',
            'counterpartyIban' => null,
            'purpose' => $purpose,
            'endToEndId' => null,
            'mandateReference' => null,
            'creditorId' => null,
            'fingerprint' => 'fp0',
            'status' => ImportState::LINE_STATUS_PENDING,
            'isIgnored' => false,
            'isDuplicate' => false,
            'userDebitAccountId' => null,
            'userCreditAccountId' => null,
            'userTaxRateId' => null,
            'userRemark' => null,
            'appliedRuleId' => null,
            'matchedInvoiceId' => null,
            'matchedInvoiceNumber' => null,
            'matchedInvoiceAmountMatches' => false,
            'splits' => [],
        ];

        return $state;
    }
}
