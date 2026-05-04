<?php

declare(strict_types=1);

namespace App\Dto\BookingJournal\BankImport;

use App\Entity\BankCsvProfile;

/**
 * Result of the bank-import format dropdown after submit. The form picks one
 * of "iso20022_camt" or a "csv:<profileId>" entry; the transformer resolves
 * that into the canonical parser format key and (for CSV) the matching
 * profile so the controller no longer has to parse strings itself.
 */
final readonly class BankImportFormatChoice
{
    public function __construct(
        public string $formatKey,
        public ?BankCsvProfile $profile,
    ) {
    }
}
