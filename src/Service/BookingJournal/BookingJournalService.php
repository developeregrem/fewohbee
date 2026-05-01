<?php

declare(strict_types=1);

namespace App\Service\BookingJournal;

use App\Entity\AccountingAccount;
use App\Entity\BookingBatch;
use App\Entity\BookingEntry;
use App\Entity\Invoice;
use App\Entity\TaxRate;
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
        ?\DateTimeInterface $bookingDate = null,
        string $sourceType = BookingEntry::SOURCE_WORKFLOW,
    ): array {
        $bookingDate = null !== $bookingDate
            ? \DateTime::createFromInterface($bookingDate)
            : new \DateTime();
        $year = (int) $bookingDate->format('Y');
        $month = (int) $bookingDate->format('n');

        $batch = $this->getOrCreateBatch($year, $month);

        if ($batch->isClosed()) {
            throw new \RuntimeException(
                $this->translator->trans('journal.error.journal.closed', [
                    '%month%' => $month,
                    '%year%' => $year,
                ])
            );
        }

        $activePreset = $this->settingsService->getActivePreset();

        // If no debit account given, default to cash account (backwards compat)
        if (null === $debitAccount) {
            $debitAccount = $this->accountRepo->findCashAccount($activePreset);
        }

        // Group by (scope, vatRate, effectiveCreditAccountId). Grouping must use the *resolved*
        // credit account (override → TaxRate → fallback), not the raw override — otherwise a
        // position with an explicit account that happens to match the tax-rate default would
        // split off into its own journal line. Scopes stay separate so apartment
        // ("Hauptleistung") and misc ("Sonstige Leistungen") surface as distinct entries even
        // when they hit the same account + VAT.
        $taxRateCache = [];
        $resolveTaxRate = function (string $vatKey) use (&$taxRateCache, $bookingDate, $activePreset) {
            if (!array_key_exists($vatKey, $taxRateCache)) {
                // Strip the "v" prefix added to prevent PHP int-casting numeric string keys.
                $taxRateCache[$vatKey] = $this->taxRateRepo->findByRate((float) ltrim($vatKey, 'v'), $bookingDate, $activePreset);
            }

            return $taxRateCache[$vatKey];
        };
        $resolveCreditAccount = function (?AccountingAccount $override, string $vatKey) use ($resolveTaxRate, $creditAccount) {
            return $override ?? $resolveTaxRate($vatKey)?->getRevenueAccount() ?? $creditAccount;
        };
        // Prefix prevents PHP from silently casting numeric string keys (e.g. "19") to int.
        $vatKeyOf = fn (float $vat): string => 'v'.(string) $vat;

        $groups = [
            'apartment' => [],
            'misc' => [],
        ];
        foreach ($invoice->getAppartments() as $apartment) {
            $apartmentPrice = $apartment->getTotalPriceRaw();
            $bruttoAmount = $apartment->getIncludesVat()
                ? $apartmentPrice
                : $apartmentPrice + ($apartmentPrice * $apartment->getVat()) / 100;
            $vatKey = $vatKeyOf($apartment->getVat());
            $effective = $resolveCreditAccount($apartment->getRevenueAccount(), $vatKey);
            $accountId = $effective?->getId() ?? 0;
            if (!isset($groups['apartment'][$vatKey][$accountId])) {
                $groups['apartment'][$vatKey][$accountId] = ['brutto' => 0.0, 'account' => $effective];
            }
            $groups['apartment'][$vatKey][$accountId]['brutto'] += $bruttoAmount;
        }
        foreach ($invoice->getPositions() as $pos) {
            $posPrice = $pos->getTotalPriceRaw();
            $bruttoAmount = $pos->getIncludesVat()
                ? $posPrice
                : $posPrice + ($posPrice * $pos->getVat()) / 100;
            $vatKey = $vatKeyOf($pos->getVat());
            $effective = $resolveCreditAccount($pos->getRevenueAccount(), $vatKey);
            $accountId = $effective?->getId() ?? 0;
            if (!isset($groups['misc'][$vatKey][$accountId])) {
                $groups['misc'][$vatKey][$accountId] = ['brutto' => 0.0, 'account' => $effective];
            }
            $groups['misc'][$vatKey][$accountId]['brutto'] += $bruttoAmount;
        }

        $settings = $this->settingsService->getSettings();
        $scopeLabels = [
            'apartment' => $settings->getMainPositionLabel(),
            'misc' => $settings->getMiscPositionLabel(),
        ];

        $nextDocNumber = $this->entryRepo->getLastDocumentNumber($batch) + 1;
        $entries = [];

        foreach ($groups as $scope => $byVat) {
            ksort($byVat);
            foreach ($byVat as $vatKey => $byAccount) {
                $taxRate = $resolveTaxRate($vatKey);

                foreach ($byAccount as $row) {
                    $vatBrutto = round($row['brutto'], 2);
                    if (0.0 === $vatBrutto) {
                        continue;
                    }

                    $entryCreditAccount = $row['account'];

                    $entry = new BookingEntry();
                    $entry->setDate(clone $bookingDate);
                    $entry->setDocumentNumber($nextDocNumber++);
                    $entry->setAmount($vatBrutto);
                    $entry->setDebitAccount($debitAccount);
                    $entry->setCreditAccount($entryCreditAccount);
                    $entry->setTaxRate($taxRate);
                    $entry->setInvoiceNumber($invoice->getNumber());
                    $entry->setInvoiceId($invoice->getId());
                    // Remark: explicit param → "{scope label} – {account name}" → account name → debit account name.
                    $accountName = $entryCreditAccount?->getName() ?? '';
                    $scopeLabel = $scopeLabels[$scope] ?? null;
                    $defaultRemark = $scopeLabel && $accountName
                        ? $scopeLabel.' – '.$accountName
                        : ($accountName ?: ($debitAccount?->getName() ?? ''));
                    $entry->setRemark($remark ?: $defaultRemark);
                    $entry->setSourceType($sourceType);
                    $entry->setBookingBatch($batch);
                    $batch->addEntry($entry);

                    $this->em->persist($entry);
                    $entries[] = $entry;
                }
            }
        }

        $this->em->flush();
        $this->recalculateDocumentNumbersForYears($year);

        return $entries;
    }

    /**
     * Creates one BookingEntry for a single statement piece — either a whole
     * line or one of its splits. Persisted (but not yet flushed) and assigned
     * to the matching month batch.
     *
     * Caller is expected to set Source via {@see BookingEntry::setSourceType()}
     * (typically SOURCE_MANUAL — bank-import is a kind of manual booking).
     */
    public function createEntryFromStatement(
        \DateTimeInterface $date,
        string $amount,
        ?AccountingAccount $debitAccount,
        ?AccountingAccount $creditAccount,
        ?string $remark,
        ?string $invoiceNumber = null,
        ?int $invoiceId = null,
        ?string $splitGroupUuid = null,
        ?TaxRate $taxRate = null,
    ): BookingEntry {
        $entry = new BookingEntry();
        $entry->setDate(\DateTime::createFromInterface($date));
        $entry->setAmount($amount);
        $entry->setDebitAccount($debitAccount);
        $entry->setCreditAccount($creditAccount);
        $entry->setRemark($remark);
        $entry->setInvoiceNumber($invoiceNumber);
        $entry->setInvoiceId($invoiceId);
        $entry->setTaxRate($taxRate);
        $entry->setSourceType(BookingEntry::SOURCE_MANUAL);
        if (null !== $splitGroupUuid) {
            $entry->setSplitGroupUuid($splitGroupUuid);
        }

        $batch = $this->assignBatchByEntryDate($entry);
        $entry->setDocumentNumber($this->entryRepo->getLastDocumentNumber($batch) + 1);
        $this->em->persist($entry);

        return $entry;
    }

    /**
     * Moves an existing entry to a new date — used when a bank statement reveals
     * the actual value date for an invoice that was previously booked on the
     * invoice date by a workflow.
     *
     * Re-assigns the entry to the matching month batch when the month changes
     * and re-numbers the affected year(s). Throws when either the source or the
     * target batch is closed.
     */
    public function updateEntryDate(BookingEntry $entry, \DateTimeInterface $newDate): void
    {
        $newDate = \DateTime::createFromInterface($newDate);
        if ($entry->getDate()->format('Y-m-d') === $newDate->format('Y-m-d')) {
            return;
        }

        $oldBatch = $entry->getBookingBatch();
        if ($oldBatch->isClosed()) {
            throw new \RuntimeException(
                $this->translator->trans('journal.error.journal.closed', [
                    '%month%' => $oldBatch->getMonth(),
                    '%year%' => $oldBatch->getYear(),
                ])
            );
        }

        $entry->setDate($newDate);

        $oldYear = $oldBatch->getYear();
        $oldMonth = $oldBatch->getMonth();
        $newYear = (int) $newDate->format('Y');
        $newMonth = (int) $newDate->format('n');

        if ($oldYear !== $newYear || $oldMonth !== $newMonth) {
            $this->assignBatchByEntryDate($entry); // throws if target closed
        }

        $this->em->flush();
        $years = array_unique([$oldYear, $newYear]);
        $this->recalculateDocumentNumbersForYears(...$years);
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
