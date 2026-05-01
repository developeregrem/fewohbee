<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\BankImport;

use App\Dto\BookingJournal\BankImport\ImportState;
use App\Entity\AccountingAccount;
use App\Entity\BankImportFingerprint;
use App\Entity\BankStatementImport;
use App\Entity\BookingEntry;
use App\Entity\User;
use App\Repository\AccountingAccountRepository;
use App\Repository\BookingEntryRepository;
use App\Repository\InvoiceRepository;
use App\Repository\TaxRateRepository;
use App\Service\BookingJournal\BookingJournalService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Turns the in-progress {@see ImportState} into actual journal entries.
 *
 * For each non-duplicate, non-ignored line the committer either
 *  (a) creates one or more new {@see BookingEntry}s (one per split, sharing a
 *      splitGroupUuid; or one entry if the line is not split), or
 *  (b) re-dates existing entries that were already posted by a workflow when
 *      the matching invoice's status changed.
 *
 * Every committed line — including ignored ones — is recorded as a
 * {@see BankImportFingerprint} so a re-import recognises it as a duplicate.
 *
 * The whole flow runs in a single DB transaction. On success the session
 * draft is discarded and a {@see BankStatementImport} audit row remains.
 */
final class BankStatementCommitter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BookingJournalService $journal,
        private readonly BookingEntryRepository $entryRepo,
        private readonly AccountingAccountRepository $accountRepo,
        private readonly InvoiceRepository $invoiceRepo,
        private readonly TaxRateRepository $taxRateRepo,
        private readonly BankImportDraftSession $drafts,
    ) {
    }

    /**
     * @return array{importId: int, committed: int, ignored: int, duplicates: int, redated: int}
     */
    public function commit(ImportState $state, AccountingAccount $bankAccount, ?User $user = null): array
    {
        $accounts = $this->loadAccountsById();
        $taxRates = $this->loadTaxRatesById();
        $this->em->beginTransaction();

        try {
            $audit = new BankStatementImport($bankAccount);
            $audit->setCreatedBy($user);
            $audit->setFileFormat($state->fileFormat);
            $audit->setStatus(BankStatementImport::STATUS_COMMITTED);
            $audit->setCommittedAt(new \DateTimeImmutable());
            $audit->setPeriodFrom($this->parseDate($state->periodFrom));
            $audit->setPeriodTo($this->parseDate($state->periodTo));
            $this->em->persist($audit);

            $committed = 0;
            $ignored = 0;
            $duplicates = 0;
            $redated = 0;
            $statementEntryYears = [];

            foreach ($state->lines as $line) {
                if (true === ($line['isDuplicate'] ?? false)) {
                    ++$duplicates;
                    continue;
                }

                $hash = (string) ($line['fingerprint'] ?? '');
                $fingerprint = new BankImportFingerprint($bankAccount, $hash);
                $fingerprint->setStatementImport($audit);

                if (true === ($line['isIgnored'] ?? false)) {
                    $this->em->persist($fingerprint);
                    ++$ignored;
                    continue;
                }

                if (ImportState::LINE_STATUS_READY !== ($line['status'] ?? '')) {
                    // Skip pending lines silently — caller is expected to gate
                    // on this; we just avoid bad data sneaking in.
                    continue;
                }

                $valueDate = new \DateTimeImmutable((string) ($line['valueDate'] ?? $line['bookDate']));

                // Re-date existing workflow entries when this line maps to a
                // known invoice that has already been booked.
                $existing = null !== ($line['matchedInvoiceId'] ?? null)
                    ? $this->entryRepo->findBy(['invoiceId' => (int) $line['matchedInvoiceId']])
                    : [];

                if (null !== ($line['matchedInvoiceId'] ?? null) && [] === ($line['splits'] ?? [])) {
                    if ([] !== $existing && true === ($line['matchedInvoiceAmountMatches'] ?? false)) {
                        $this->updateExistingInvoiceEntries($existing, $line, $valueDate, $bankAccount);
                        $fingerprint->setBookingEntry($existing[0]);
                        $this->em->persist($fingerprint);
                        $redated += count($existing);
                        ++$committed;
                        continue;
                    }

                    $invoice = $this->invoiceRepo->find((int) $line['matchedInvoiceId']);
                    if (null !== $invoice && true === ($line['matchedInvoiceAmountMatches'] ?? false)) {
                        $newEntries = $this->journal->createEntriesFromInvoice(
                            $invoice,
                            $bankAccount,
                            null,
                            $line['userRemark'] ?? null,
                            $valueDate,
                            BookingEntry::SOURCE_MANUAL,
                        );
                        if ([] === $newEntries) {
                            continue;
                        }

                        $fingerprint->setBookingEntry($newEntries[0]);
                        $this->em->persist($fingerprint);
                        ++$committed;
                        continue;
                    }
                }

                // Otherwise create fresh entries.
                $newEntries = $this->createEntries($line, $valueDate, $accounts, $taxRates);
                if ([] === $newEntries) {
                    continue;
                }
                $statementEntryYears[] = (int) $valueDate->format('Y');
                // Link the fingerprint to the first new entry — that's what
                // appears on the journal page anyway.
                $fingerprint->setBookingEntry($newEntries[0]);
                $this->em->persist($fingerprint);
                ++$committed;
            }

            $audit->setLineCountTotal(count($state->lines));
            $audit->setLineCountCommitted($committed);
            $audit->setLineCountIgnored($ignored);
            $audit->setLineCountDuplicate($duplicates);

            $this->em->flush();
            if ([] !== $statementEntryYears) {
                $this->journal->recalculateDocumentNumbersForYears(...array_values(array_unique($statementEntryYears)));
            }
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }

        $this->drafts->discard($state->sessionImportId);

        return [
            'importId' => (int) $audit->getId(),
            'committed' => $committed,
            'ignored' => $ignored,
            'duplicates' => $duplicates,
            'redated' => $redated,
        ];
    }

    /**
     * @param list<BookingEntry>      $entries
     * @param array<string, mixed>    $line
     */
    private function updateExistingInvoiceEntries(array $entries, array $line, \DateTimeImmutable $valueDate, AccountingAccount $bankAccount): void
    {
        $isIncoming = ((float) ($line['amount'] ?? 0)) >= 0.0;

        foreach ($entries as $entry) {
            if ($isIncoming) {
                $entry->setDebitAccount($bankAccount);
            } else {
                $entry->setCreditAccount($bankAccount);
            }

            $this->journal->updateEntryDate($entry, $valueDate);
        }
    }

    /**
     * @param array<string, mixed>          $line
     * @param array<int, AccountingAccount> $accounts
     * @param array<int, \App\Entity\TaxRate> $taxRates
     *
     * @return list<BookingEntry>
     */
    private function createEntries(array $line, \DateTimeImmutable $valueDate, array $accounts, array $taxRates): array
    {
        $invoiceId = $line['matchedInvoiceId'] ?? null;
        $invoiceNumber = $line['matchedInvoiceNumber'] ?? null;

        $splits = $line['splits'] ?? [];
        if (!is_array($splits) || [] === $splits) {
            $entry = $this->journal->createEntryFromStatement(
                $valueDate,
                $this->journalAmount($line['amount'] ?? '0.00'),
                $accounts[(int) ($line['userDebitAccountId'] ?? 0)] ?? null,
                $accounts[(int) ($line['userCreditAccountId'] ?? 0)] ?? null,
                $line['userRemark'] ?? null,
                $invoiceNumber,
                $invoiceId !== null ? (int) $invoiceId : null,
                null,
                $taxRates[(int) ($line['userTaxRateId'] ?? 0)] ?? null,
            );

            return [$entry];
        }

        $groupUuid = Uuid::v4()->toRfc4122();
        $created = [];
        foreach ($splits as $split) {
            $created[] = $this->journal->createEntryFromStatement(
                $valueDate,
                $this->journalAmount($split['amount'] ?? '0.00'),
                $accounts[(int) ($split['debitAccountId'] ?? 0)] ?? null,
                $accounts[(int) ($split['creditAccountId'] ?? 0)] ?? null,
                $split['remark'] ?? null,
                $invoiceNumber,
                $invoiceId !== null ? (int) $invoiceId : null,
                $groupUuid,
                $taxRates[(int) ($split['taxRateId'] ?? 0)] ?? null,
            );
        }

        return $created;
    }

    private function journalAmount(mixed $amount): string
    {
        return number_format(abs((float) $amount), 2, '.', '');
    }

    /**
     * @return array<int, AccountingAccount>
     */
    private function loadAccountsById(): array
    {
        $accounts = [];
        foreach ($this->accountRepo->findAll() as $account) {
            $accounts[(int) $account->getId()] = $account;
        }

        return $accounts;
    }

    /**
     * @return array<int, \App\Entity\TaxRate>
     */
    private function loadTaxRatesById(): array
    {
        $taxRates = [];
        foreach ($this->taxRateRepo->findAll() as $taxRate) {
            $taxRates[(int) $taxRate->getId()] = $taxRate;
        }

        return $taxRates;
    }

    private function parseDate(?string $value): ?\DateTime
    {
        if (null === $value || '' === $value) {
            return null;
        }
        try {
            return new \DateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
