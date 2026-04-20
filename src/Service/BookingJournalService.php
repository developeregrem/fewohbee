<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccountingAccount;
use App\Entity\BookingBatch;
use App\Entity\BookingEntry;
use App\Entity\Invoice;
use App\Repository\AccountingAccountRepository;
use App\Repository\BookingBatchRepository;
use App\Repository\BookingEntryRepository;
use App\Repository\TaxRateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class BookingJournalService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BookingBatchRepository $batchRepo,
        private readonly BookingEntryRepository $entryRepo,
        private readonly AccountingAccountRepository $accountRepo,
        private readonly TaxRateRepository $taxRateRepo,
        private readonly InvoiceService $invoiceService,
        private readonly TranslatorInterface $translator,
        private readonly AccountingSettingsService $settingsService,
    ) {
    }

    /**
     * Get or create a BookingBatch for the given year/month.
     */
    public function getOrCreateBatch(int $year, int $month): BookingBatch
    {
        $batch = $this->batchRepo->findByYearAndMonth($year, $month);

        if (null !== $batch) {
            return $batch;
        }

        $batch = new BookingBatch();
        $batch->setYear($year);
        $batch->setMonth($month);

        $this->em->persist($batch);

        return $batch;
    }

    /**
     * Assigns an entry to the month batch implied by its booking date.
     */
    public function assignBatchByEntryDate(BookingEntry $entry): BookingBatch
    {
        $date = $entry->getDate();
        $batch = $this->getOrCreateBatch((int) $date->format('Y'), (int) $date->format('n'));

        if ($batch->isClosed()) {
            throw new \RuntimeException(
                $this->translator->trans('journal.error.journal.closed', [
                    '%month%' => $batch->getMonth(),
                    '%year%' => $batch->getYear(),
                ])
            );
        }

        $entry->setBookingBatch($batch);

        return $batch;
    }

    /**
     * Populate transient cashStart/cashEnd on a single batch (computed on the fly).
     */
    public function populateCashBalance(BookingBatch $batch): void
    {
        $start = $this->entryRepo->getCashOpeningBalance($batch);
        $delta = $this->entryRepo->getCashBatchDelta($batch);
        $batch->setCashStart(round($start, 2));
        $batch->setCashEnd(round($start + $delta, 2));
    }

    /**
     * Populate transient cashStart/cashEnd on all batches of a year using two bulk queries.
     *
     * @param BookingBatch[] $batches batches of the given year (any order)
     */
    public function populateCashBalances(array $batches, int $year): void
    {
        if ([] === $batches) {
            return;
        }

        $opening = $this->entryRepo->getCashOpeningForYear($year);
        $deltas = $this->entryRepo->getCashDeltasByMonth($year);

        $sorted = $batches;
        usort($sorted, fn (BookingBatch $a, BookingBatch $b) => $a->getMonth() <=> $b->getMonth());

        $running = $opening;
        foreach ($sorted as $batch) {
            $batch->setCashStart(round($running, 2));
            $running += $deltas[$batch->getMonth()] ?? 0.0;
            $batch->setCashEnd(round($running, 2));
        }
    }

    /**
     * Renumber document numbers within a batch's year.
     */
    public function recalculateDocumentNumbers(BookingBatch $batch): void
    {
        $this->recalculateDocumentNumbersForYears($batch->getYear());
    }

    public function recalculateDocumentNumbersForYears(int ...$years): void
    {
        $years = array_values(array_unique($years));
        sort($years);

        foreach ($years as $year) {
            $documentNumber = 1;
            foreach ($this->entryRepo->findEntriesForDocumentNumbering($year) as $entry) {
                $entry->setDocumentNumber($documentNumber++);
            }
        }

        $this->em->flush();
    }

    /**
     * Creates BookingEntries from an Invoice, one per VAT rate.
     *
     * Each VAT rate on the invoice produces a separate entry with the brutto
     * amount for that rate. The TaxRate entity is matched by rate value, and
     * its revenueAccount is used as the credit account (fallback: $creditAccount).
     *
     * @return BookingEntry[] created entries (one per VAT rate)
     */
    public function createEntriesFromInvoice(
        Invoice $invoice,
        ?AccountingAccount $debitAccount = null,
        ?AccountingAccount $creditAccount = null,
        ?string $remark = null,
    ): array {
        $today = new \DateTime();
        $year = (int) $today->format('Y');
        $month = (int) $today->format('n');

        $batch = $this->getOrCreateBatch($year, $month);

        if ($batch->isClosed()) {
            throw new \RuntimeException(
                $this->translator->trans('journal.error.journal.closed', [
                    '%month%' => $month,
                    '%year%' => $year,
                ])
            );
        }

        // Calculate VAT breakdown
        $vats = [];
        $brutto = 0.0;
        $netto = 0.0;
        $appartmentTotal = 0.0;
        $miscTotal = 0.0;
        $this->invoiceService->calculateSums(
            $invoice->getAppartments(),
            $invoice->getPositions(),
            $vats,
            $brutto,
            $netto,
            $appartmentTotal,
            $miscTotal,
        );

        $activePreset = $this->settingsService->getActivePreset();

        // If no debit account given, default to cash account (backwards compat)
        if (null === $debitAccount) {
            $debitAccount = $this->accountRepo->findCashAccount($activePreset);
        }

        $nextDocNumber = $this->entryRepo->getLastDocumentNumber($batch) + 1;
        $entries = [];

        foreach ($vats as $vatRate => $vatData) {
            $vatBrutto = round($vatData['brutto'], 2);
            if (0.0 === $vatBrutto) {
                continue;
            }

            // Match TaxRate entity by rate value, respecting validity
            $taxRate = $this->taxRateRepo->findByRate((float) $vatRate, $today, $activePreset);

            // Credit account: TaxRate's revenueAccount → fallback to explicit param
            $entryCreditAccount = $taxRate?->getRevenueAccount() ?? $creditAccount;

            $entry = new BookingEntry();
            $entry->setDate(clone $today);
            $entry->setDocumentNumber($nextDocNumber++);
            $entry->setAmount($vatBrutto);
            $entry->setDebitAccount($debitAccount);
            $entry->setCreditAccount($entryCreditAccount);
            $entry->setTaxRate($taxRate);
            $entry->setInvoiceNumber($invoice->getNumber());
            $entry->setInvoiceId($invoice->getId());
            // Buchungstext fallback: explicit remark → credit account name → debit account name.
            $entry->setRemark($remark ?: ($entryCreditAccount?->getName() ?: ($debitAccount?->getName() ?? '')));
            $entry->setSourceType(BookingEntry::SOURCE_WORKFLOW);
            $entry->setBookingBatch($batch);
            $batch->addEntry($entry);

            $this->em->persist($entry);
            $entries[] = $entry;
        }

        $this->em->flush();
        $this->recalculateDocumentNumbersForYears($year);

        return $entries;
    }

    /**
     * Build template render params for Kassenbuch PDF export.
     */
    public function buildTemplateRenderParams(int $batchId): array
    {
        $batch = $this->batchRepo->find($batchId);
        if (null !== $batch) {
            $this->populateCashBalance($batch);
        }

        return [
            'journal' => $batch,
        ];
    }
}
