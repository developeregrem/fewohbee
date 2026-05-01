<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\BankImport;

use App\Dto\BookingJournal\BankImport\ImportState;
use App\Entity\AccountingAccount;
use App\Repository\BankImportFingerprintRepository;

/**
 * Marks lines that have already been committed in a previous import so the user
 * can ignore them at a glance. Compares each line's fingerprint against the
 * fingerprints stored for the same bank account.
 */
final class BankStatementDeduplicator
{
    public function __construct(
        private readonly BankImportFingerprintRepository $fingerprintRepo,
    ) {
    }

    public function annotate(ImportState $state, AccountingAccount $bankAccount): void
    {
        if ([] === $state->lines) {
            return;
        }

        $hashes = array_map(static fn (array $line): string => (string) $line['fingerprint'], $state->lines);
        $existing = array_flip($this->fingerprintRepo->findExistingHashes($bankAccount, $hashes));

        foreach ($state->lines as &$line) {
            if (isset($existing[$line['fingerprint']])) {
                $line['isDuplicate'] = true;
                $line['status'] = ImportState::LINE_STATUS_DUPLICATE;
            }
        }
        unset($line);
    }
}
