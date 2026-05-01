<?php

declare(strict_types=1);

namespace App\Service\BookingJournal;

use App\Entity\AccountingAccount;
use App\Entity\BookingBatch;
use App\Entity\BookingEntry;
use App\Repository\AccountingAccountRepository;
use App\Repository\BookingBatchRepository;
use App\Repository\BookingEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manages opening-balance (Eröffnungsbuchungen) bookings.
 *
 * For a given year and asset account (cash or bank), maintains exactly one
 * BookingEntry on 01.01. of that year in the January batch, booked
 *   Soll: asset account   Haben: opening-balance account.
 *
 * Amount 0.00 removes the entry. Idempotent.
 */
class OpeningBalanceService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BookingBatchRepository $batchRepo,
        private readonly BookingEntryRepository $entryRepo,
        private readonly AccountingAccountRepository $accountRepo,
        private readonly AccountingSettingsService $settingsService,
    ) {
    }

    /**
     * Upserts the opening-balance entry for the given asset account/year.
     * Pass amount 0 (or null) to remove it.
     */
    public function upsert(int $year, AccountingAccount $assetAccount, ?float $amount): void
    {
        $openingAccount = $this->accountRepo->findOpeningBalanceAccount($this->settingsService->getActivePreset());
        if (null === $openingAccount) {
            throw new \RuntimeException('No opening balance account configured.');
        }

        $existing = $this->entryRepo->findOpeningBalanceEntry($year, $assetAccount);

        if (null === $amount || 0.0 === (float) $amount) {
            if (null !== $existing) {
                $this->em->remove($existing);
                $this->em->flush();
            }

            return;
        }

        if (null !== $existing) {
            $existing->setAmount($amount);
            $existing->setDebitAccount($assetAccount);
            $existing->setCreditAccount($openingAccount);
            $this->em->flush();

            return;
        }

        $batch = $this->getOrCreateJanuaryBatch($year);

        $entry = new BookingEntry();
        $entry->setBookingBatch($batch);
        $entry->setDate(new \DateTime(sprintf('%04d-01-01', $year)));
        $entry->setDocumentNumber(0);
        $entry->setAmount($amount);
        $entry->setDebitAccount($assetAccount);
        $entry->setCreditAccount($openingAccount);
        $entry->setRemark('Anfangsbestand');
        $entry->setSourceType(BookingEntry::SOURCE_OPENING_BALANCE);

        $this->em->persist($entry);
        $this->em->flush();
    }

    /**
     * Returns the current opening-balance amount for the given asset account/year (0 if none).
     */
    public function getAmount(int $year, AccountingAccount $assetAccount): float
    {
        $entry = $this->entryRepo->findOpeningBalanceEntry($year, $assetAccount);

        return null === $entry ? 0.0 : (float) $entry->getAmount();
    }

    /**
     * Prior year's cash closing balance — opening + sum of all cash movements in year - 1.
     */
    public function getPriorYearCashClosing(int $year): float
    {
        $opening = $this->entryRepo->getCashOpeningForYear($year - 1);
        $deltas = $this->entryRepo->getCashDeltasByMonth($year - 1);

        return (float) ($opening + array_sum($deltas));
    }

    /**
     * Prior year's bank closing balance — net sum of all bank movements in year - 1.
     */
    public function getPriorYearBankClosing(int $year): float
    {
        $batches = $this->batchRepo->findByYear($year - 1);
        if ([] === $batches) {
            return 0.0;
        }
        $last = $batches[array_key_last($batches)];

        return (float) ($this->entryRepo->getBankOpeningBalance($last) + $this->entryRepo->getBankBatchDelta($last));
    }

    private function getOrCreateJanuaryBatch(int $year): BookingBatch
    {
        $batch = $this->batchRepo->findByYearAndMonth($year, 1);
        if (null !== $batch) {
            return $batch;
        }

        $batch = new BookingBatch();
        $batch->setYear($year);
        $batch->setMonth(1);
        $this->em->persist($batch);
        $this->em->flush();

        return $batch;
    }
}
