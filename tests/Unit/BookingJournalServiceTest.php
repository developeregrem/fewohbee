<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AccountingAccount;
use App\Entity\BookingBatch;
use App\Entity\BookingEntry;
use App\Entity\Invoice;
use App\Entity\TaxRate;
use App\Repository\AccountingAccountRepository;
use App\Repository\BookingBatchRepository;
use App\Repository\BookingEntryRepository;
use App\Repository\TaxRateRepository;
use App\Service\AccountingSettingsService;
use App\Service\BookingJournalService;
use App\Service\InvoiceService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BookingJournalServiceTest extends TestCase
{
    // ── populateCashBalance ──────────────────────────────────────────

    public function testPopulateCashBalanceComputesFromRepo(): void
    {
        $batch = new BookingBatch();
        $batch->setYear(2026);
        $batch->setMonth(4);

        $entryRepo = $this->createStub(BookingEntryRepository::class);
        $entryRepo->method('getCashOpeningBalance')->willReturn(100.0);
        $entryRepo->method('getCashBatchDelta')->willReturn(30.0);

        $service = $this->createService(entryRepo: $entryRepo);
        $service->populateCashBalance($batch);

        self::assertSame(100.0, $batch->getCashStart());
        self::assertSame(130.0, $batch->getCashEnd());
    }

    public function testPopulateCashBalancesAcrossMonthsCarriesRunningTotal(): void
    {
        $jan = (new BookingBatch())->setYear(2026)->setMonth(1);
        $feb = (new BookingBatch())->setYear(2026)->setMonth(2);
        $mar = (new BookingBatch())->setYear(2026)->setMonth(3);

        $entryRepo = $this->createStub(BookingEntryRepository::class);
        $entryRepo->method('getCashOpeningForYear')->willReturn(100.0);
        $entryRepo->method('getCashDeltasByMonth')->willReturn([
            1 => 50.0,
            2 => -20.0,
            3 => 10.0,
        ]);

        $service = $this->createService(entryRepo: $entryRepo);
        $service->populateCashBalances([$mar, $jan, $feb], 2026);

        self::assertSame(100.0, $jan->getCashStart());
        self::assertSame(150.0, $jan->getCashEnd());
        self::assertSame(150.0, $feb->getCashStart());
        self::assertSame(130.0, $feb->getCashEnd());
        self::assertSame(130.0, $mar->getCashStart());
        self::assertSame(140.0, $mar->getCashEnd());
    }

    public function testPopulateCashBalancesNoOpOnEmpty(): void
    {
        $entryRepo = $this->createStub(BookingEntryRepository::class);
        $service = $this->createService(entryRepo: $entryRepo);

        $service->populateCashBalances([], 2026);

        self::assertTrue(true); // no exception
    }

    public function testAssignBatchByEntryDateMovesEntryToMatchingBatch(): void
    {
        $oldBatch = (new BookingBatch())->setYear(2026)->setMonth(4);
        $targetBatch = (new BookingBatch())->setYear(2026)->setMonth(5);

        $entry = new BookingEntry();
        $entry->setBookingBatch($oldBatch);
        $entry->setDate(new \DateTime('2026-05-12'));

        $batchRepo = $this->createStub(BookingBatchRepository::class);
        $batchRepo->method('findByYearAndMonth')->willReturn($targetBatch);

        $service = $this->createService(batchRepo: $batchRepo);

        self::assertSame($targetBatch, $service->assignBatchByEntryDate($entry));
        self::assertSame($targetBatch, $entry->getBookingBatch());
    }

    public function testAssignBatchByEntryDateRejectsClosedTargetBatch(): void
    {
        $oldBatch = (new BookingBatch())->setYear(2026)->setMonth(4);
        $targetBatch = (new BookingBatch())->setYear(2026)->setMonth(5);
        $targetBatch->setIsClosed(true);

        $entry = new BookingEntry();
        $entry->setBookingBatch($oldBatch);
        $entry->setDate(new \DateTime('2026-05-12'));

        $batchRepo = $this->createStub(BookingBatchRepository::class);
        $batchRepo->method('findByYearAndMonth')->willReturn($targetBatch);

        $service = $this->createService(batchRepo: $batchRepo);

        $this->expectException(\RuntimeException::class);
        $service->assignBatchByEntryDate($entry);
    }

    public function testRecalculateDocumentNumbersForYearsAssignsGaplessNumbers(): void
    {
        $first = (new BookingEntry())->setDocumentNumber(12);
        $second = (new BookingEntry())->setDocumentNumber(30);

        $entryRepo = $this->createStub(BookingEntryRepository::class);
        $entryRepo->method('findEntriesForDocumentNumbering')
            ->willReturn([$first, $second]);

        $em = $this->createStub(EntityManagerInterface::class);

        $service = $this->createService(em: $em, entryRepo: $entryRepo);

        $service->recalculateDocumentNumbersForYears(2026);

        self::assertSame(1, $first->getDocumentNumber());
        self::assertSame(2, $second->getDocumentNumber());
    }

    // ── createEntriesFromInvoice ────────────────────────────────────

    public function testCreateEntriesFromInvoiceCreatesOneEntryPerVatRate(): void
    {
        $cash = $this->makeCashAccount();
        $revenue7 = $this->makeAccount('8300', 'Erlöse 7%', false);
        $revenue19 = $this->makeAccount('8400', 'Erlöse 19%', false);

        $taxRate7 = $this->makeTaxRate('7%', '7.00', $revenue7);
        $taxRate19 = $this->makeTaxRate('19%', '19.00', $revenue19);

        $batch = new BookingBatch();
        $batch->setYear(2026);
        $batch->setMonth(4);

        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getAppartments')->willReturn(new ArrayCollection());
        $invoice->method('getPositions')->willReturn(new ArrayCollection());
        $invoice->method('getNumber')->willReturn('R-2026-001');
        $invoice->method('getId')->willReturn(42);

        $batchRepo = $this->createStub(BookingBatchRepository::class);
        $batchRepo->method('findByYearAndMonth')->willReturn($batch);

        $entryRepo = $this->createStub(BookingEntryRepository::class);
        $entryRepo->method('getLastDocumentNumber')->willReturn(0);

        $accountRepo = $this->createStub(AccountingAccountRepository::class);
        $accountRepo->method('findCashAccount')->willReturn($cash);

        $taxRateRepo = $this->createStub(TaxRateRepository::class);
        $taxRateRepo->method('findByRate')->willReturnCallback(
            fn (float $rate) => match (true) {
                abs($rate - 7.0) < 0.01 => $taxRate7,
                abs($rate - 19.0) < 0.01 => $taxRate19,
                default => null,
            }
        );

        // Simulate calculateSums producing two VAT rates
        $invoiceService = $this->createStub(InvoiceService::class);
        $invoiceService->method('calculateSums')->willReturnCallback(
            function ($apps, $poss, &$vats, &$brutto, &$netto) {
                $vats = [
                    7 => ['brutto' => 107.00, 'netto' => 100.0],
                    19 => ['brutto' => 119.00, 'netto' => 100.0],
                ];
                $brutto = 226.0;
                $netto = 200.0;
            }
        );

        $service = $this->createService(
            batchRepo: $batchRepo,
            entryRepo: $entryRepo,
            accountRepo: $accountRepo,
            taxRateRepo: $taxRateRepo,
            invoiceService: $invoiceService,
        );

        $entries = $service->createEntriesFromInvoice($invoice);

        self::assertCount(2, $entries);

        // First entry: 7% VAT
        self::assertSame('107.00', $entries[0]->getAmount());
        self::assertSame($cash, $entries[0]->getDebitAccount());
        self::assertSame($revenue7, $entries[0]->getCreditAccount());
        self::assertSame($taxRate7, $entries[0]->getTaxRate());
        self::assertSame('R-2026-001', $entries[0]->getInvoiceNumber());
        self::assertSame(1, $entries[0]->getDocumentNumber());

        // Second entry: 19% VAT
        self::assertSame('119.00', $entries[1]->getAmount());
        self::assertSame($revenue19, $entries[1]->getCreditAccount());
        self::assertSame(2, $entries[1]->getDocumentNumber());
    }

    public function testCreateEntriesFromInvoiceSkipsZeroAmounts(): void
    {
        $cash = $this->makeCashAccount();
        $batch = new BookingBatch();
        $batch->setYear(2026);
        $batch->setMonth(4);

        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getAppartments')->willReturn(new ArrayCollection());
        $invoice->method('getPositions')->willReturn(new ArrayCollection());
        $invoice->method('getNumber')->willReturn('R-2026-002');
        $invoice->method('getId')->willReturn(43);

        $batchRepo = $this->createStub(BookingBatchRepository::class);
        $batchRepo->method('findByYearAndMonth')->willReturn($batch);

        $entryRepo = $this->createStub(BookingEntryRepository::class);
        $entryRepo->method('getLastDocumentNumber')->willReturn(0);

        $accountRepo = $this->createStub(AccountingAccountRepository::class);
        $accountRepo->method('findCashAccount')->willReturn($cash);

        $invoiceService = $this->createStub(InvoiceService::class);
        $invoiceService->method('calculateSums')->willReturnCallback(
            function ($apps, $poss, &$vats, &$brutto, &$netto) {
                $vats = [
                    7 => ['brutto' => 0.00, 'netto' => 0.0],
                    19 => ['brutto' => 59.50, 'netto' => 50.0],
                ];
                $brutto = 59.5;
                $netto = 50.0;
            }
        );

        $taxRateRepo = $this->createStub(TaxRateRepository::class);
        $taxRateRepo->method('findByRate')->willReturn(null);

        $service = $this->createService(
            batchRepo: $batchRepo,
            entryRepo: $entryRepo,
            accountRepo: $accountRepo,
            taxRateRepo: $taxRateRepo,
            invoiceService: $invoiceService,
        );

        $entries = $service->createEntriesFromInvoice($invoice);

        self::assertCount(1, $entries);
        self::assertSame('59.50', $entries[0]->getAmount());
    }

    public function testCreateEntriesFromInvoiceUsesFallbackCreditAccount(): void
    {
        $cash = $this->makeCashAccount();
        $fallback = $this->makeAccount('8400', 'Fallback Revenue', false);

        $batch = new BookingBatch();
        $batch->setYear(2026);
        $batch->setMonth(4);

        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getAppartments')->willReturn(new ArrayCollection());
        $invoice->method('getPositions')->willReturn(new ArrayCollection());
        $invoice->method('getNumber')->willReturn('R-2026-003');
        $invoice->method('getId')->willReturn(44);

        $batchRepo = $this->createStub(BookingBatchRepository::class);
        $batchRepo->method('findByYearAndMonth')->willReturn($batch);

        $entryRepo = $this->createStub(BookingEntryRepository::class);
        $entryRepo->method('getLastDocumentNumber')->willReturn(0);

        $accountRepo = $this->createStub(AccountingAccountRepository::class);

        // TaxRate without revenueAccount
        $taxRate = $this->makeTaxRate('19%', '19.00', null);
        $taxRateRepo = $this->createStub(TaxRateRepository::class);
        $taxRateRepo->method('findByRate')->willReturn($taxRate);

        $invoiceService = $this->createStub(InvoiceService::class);
        $invoiceService->method('calculateSums')->willReturnCallback(
            function ($apps, $poss, &$vats, &$brutto, &$netto) {
                $vats = [19 => ['brutto' => 119.0, 'netto' => 100.0]];
                $brutto = 119.0;
                $netto = 100.0;
            }
        );

        $service = $this->createService(
            batchRepo: $batchRepo,
            entryRepo: $entryRepo,
            accountRepo: $accountRepo,
            taxRateRepo: $taxRateRepo,
            invoiceService: $invoiceService,
        );

        $entries = $service->createEntriesFromInvoice($invoice, $cash, $fallback);

        self::assertCount(1, $entries);
        self::assertSame($fallback, $entries[0]->getCreditAccount());
    }

    public function testCreateEntriesFromInvoiceThrowsWhenBatchClosed(): void
    {
        $batch = new BookingBatch();
        $batch->setYear(2026);
        $batch->setMonth(4);
        $batch->setIsClosed(true);

        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getAppartments')->willReturn(new ArrayCollection());
        $invoice->method('getPositions')->willReturn(new ArrayCollection());

        $batchRepo = $this->createStub(BookingBatchRepository::class);
        $batchRepo->method('findByYearAndMonth')->willReturn($batch);

        $service = $this->createService(batchRepo: $batchRepo);

        $this->expectException(\RuntimeException::class);
        $service->createEntriesFromInvoice($invoice);
    }

    public function testCreateEntriesFromInvoiceSetsSourceTypeWorkflow(): void
    {
        $cash = $this->makeCashAccount();
        $batch = new BookingBatch();
        $batch->setYear(2026);
        $batch->setMonth(4);

        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getAppartments')->willReturn(new ArrayCollection());
        $invoice->method('getPositions')->willReturn(new ArrayCollection());
        $invoice->method('getNumber')->willReturn('R-2026-004');
        $invoice->method('getId')->willReturn(45);

        $batchRepo = $this->createStub(BookingBatchRepository::class);
        $batchRepo->method('findByYearAndMonth')->willReturn($batch);

        $entryRepo = $this->createStub(BookingEntryRepository::class);
        $entryRepo->method('getLastDocumentNumber')->willReturn(5);

        $accountRepo = $this->createStub(AccountingAccountRepository::class);
        $accountRepo->method('findCashAccount')->willReturn($cash);

        $taxRateRepo = $this->createStub(TaxRateRepository::class);
        $taxRateRepo->method('findByRate')->willReturn(null);

        $invoiceService = $this->createStub(InvoiceService::class);
        $invoiceService->method('calculateSums')->willReturnCallback(
            function ($apps, $poss, &$vats, &$brutto, &$netto) {
                $vats = [0 => ['brutto' => 50.0, 'netto' => 50.0]];
                $brutto = 50.0;
                $netto = 50.0;
            }
        );

        $service = $this->createService(
            batchRepo: $batchRepo,
            entryRepo: $entryRepo,
            accountRepo: $accountRepo,
            taxRateRepo: $taxRateRepo,
            invoiceService: $invoiceService,
        );

        $entries = $service->createEntriesFromInvoice($invoice, null, null, 'Barzahlung');

        self::assertCount(1, $entries);
        self::assertSame(BookingEntry::SOURCE_WORKFLOW, $entries[0]->getSourceType());
        self::assertSame('Barzahlung', $entries[0]->getRemark());
        self::assertSame(6, $entries[0]->getDocumentNumber());
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function createService(
        ?EntityManagerInterface $em = null,
        ?BookingBatchRepository $batchRepo = null,
        ?BookingEntryRepository $entryRepo = null,
        ?AccountingAccountRepository $accountRepo = null,
        ?TaxRateRepository $taxRateRepo = null,
        ?InvoiceService $invoiceService = null,
        ?AccountingSettingsService $settingsService = null,
    ): BookingJournalService {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        if (null === $settingsService) {
            $settingsService = $this->createStub(AccountingSettingsService::class);
            $settingsService->method('getActivePreset')->willReturn(null);
        }

        return new BookingJournalService(
            $em ?? $this->createStub(EntityManagerInterface::class),
            $batchRepo ?? $this->createStub(BookingBatchRepository::class),
            $entryRepo ?? $this->createStub(BookingEntryRepository::class),
            $accountRepo ?? $this->createStub(AccountingAccountRepository::class),
            $taxRateRepo ?? $this->createStub(TaxRateRepository::class),
            $invoiceService ?? $this->createStub(InvoiceService::class),
            $translator,
            $settingsService,
        );
    }

    private function makeCashAccount(): AccountingAccount
    {
        return $this->makeAccount('1000', 'Kasse', true);
    }

    private function makeAccount(string $number, string $name, bool $isCash): AccountingAccount
    {
        $account = new AccountingAccount();
        $account->setAccountNumber($number);
        $account->setName($name);
        $account->setIsCashAccount($isCash);

        return $account;
    }

    private function makeTaxRate(string $name, string $rate, ?AccountingAccount $revenueAccount): TaxRate
    {
        $taxRate = new TaxRate();
        $taxRate->setName($name);
        $taxRate->setRate($rate);
        $taxRate->setRevenueAccount($revenueAccount);

        return $taxRate;
    }
}
