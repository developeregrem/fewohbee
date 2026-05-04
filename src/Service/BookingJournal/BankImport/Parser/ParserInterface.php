<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\BankImport\Parser;

use App\Dto\BookingJournal\BankImport\ParseResult;
use App\Entity\BankCsvProfile;

/**
 * Implemented by every bank statement parser (CSV, CAMT.053, MT940, …).
 * Registered automatically via Symfony autoconfiguration so the registry
 * can pick the right parser for a given format key.
 */
interface ParserInterface
{
    /**
     * Stable key identifying the format this parser handles, e.g. "csv_generic".
     * The format is also persisted in BankStatementImport.fileFormat.
     */
    public function getFormatKey(): string;

    /**
     * Parses the given file. The profile is required for CSV parsers (column
     * mapping, locale, etc.). Other parsers may ignore it.
     */
    public function parse(\SplFileInfo $file, ?BankCsvProfile $profile): ParseResult;

    /**
     * Whether this format may be uploaded as multiple files at once that get
     * merged into a single ParseResult. CSV is single-file-per-upload, ISO
     * 20022 camt typically arrives as one file per booking day.
     */
    public function supportsMultipleFiles(): bool;
}
