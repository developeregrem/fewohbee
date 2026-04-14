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

        $youngest = $this->batchRepo->getYoungestBatch();
        $cashStart = $youngest?->getCashEnd() ?? 0.0;
        $batch->setCashStart($cashStart);
        $batch->setCashEnd($cashStart);

        $this->em->persist($batch);
        $this->em->flush();

        return $batch;
    }

    /**
     * Recalculate cash end for a batch based on cash-related entries.
     */
    public function recalculateCashEnd(BookingBatch $batch): void
    {
        $inventory = (float) ($batch->getCashStart() ?? 0);

        foreach ($batch->getEntries() as $entry) {
            $isCashDebit = $entry->getDebitAccount()?->isCashAccount() ?? false;
            $isCashCredit = $entry->getCreditAccount()?->isCashAccount() ?? false;

            if ($isCashDebit) {
                $inventory += (float) $entry->getAmount();
            }
            if ($isCashCredit) {
                $inventory -= (float) $entry->getAmount();
            }
        }

        $batch->setCashEnd(round($inventory, 2));
        $this->em->persist($batch);
        $this->em->flush();
    }

    /**
     * Renumber document numbers within a batch.
     */
    public function recalculateDocumentNumbers(BookingBatch $batch): void
    {
        $minDoc = $this->entryRepo->getMinDocumentNumber($batch);

        foreach ($batch->getEntries() as $entry) {
            $entry->setDocumentNumber($minDoc++);
            $this->em->persist($entry);
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

        // If no debit account given, default to cash account (backwards compat)
        if (null === $debitAccount) {
            $debitAccount = $this->accountRepo->findCashAccount();
        }

        $nextDocNumber = $this->entryRepo->getLastDocumentNumber($batch) + 1;
        $entries = [];

        foreach ($vats as $vatRate => $vatData) {
            $vatBrutto = round($vatData['brutto'], 2);
            if (0.0 === $vatBrutto) {
                continue;
            }

            // Match TaxRate entity by rate value, respecting validity
            $taxRate = $this->taxRateRepo->findByRate((float) $vatRate, $today);

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
            $entry->setRemark($remark ?? '');
            $entry->setSourceType(BookingEntry::SOURCE_WORKFLOW);
            $entry->setBookingBatch($batch);
            $batch->addEntry($entry);

            $this->em->persist($entry);
            $entries[] = $entry;
        }

        $this->em->flush();
        $this->recalculateCashEnd($batch);

        return $entries;
    }

    /**
     * Build template render params for Kassenbuch PDF export.
     */
    public function buildTemplateRenderParams(int $batchId): array
    {
        $batch = $this->batchRepo->find($batchId);

        return [
            'journal' => $batch,
        ];
    }
}
