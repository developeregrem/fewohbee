<?php

declare(strict_types=1);

namespace App\Dto\BookingJournal\BankImport;

/**
 * Output of a bank statement parser: lines plus metadata extracted from the file header.
 */
final class ParseResult
{
    /**
     * @param list<StatementLineDto> $lines
     * @param list<string>           $warnings non-fatal issues encountered while parsing
     */
    public function __construct(
        public readonly array $lines,
        public readonly ?string $sourceIban = null,
        public readonly ?\DateTimeImmutable $periodFrom = null,
        public readonly ?\DateTimeImmutable $periodTo = null,
        public readonly array $warnings = [],
    ) {
    }
}
