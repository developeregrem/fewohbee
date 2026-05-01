<?php

declare(strict_types=1);

namespace App\Dto\BookingJournal\BankImport;

/**
 * Parsed representation of a single line in a bank statement.
 * Carries only raw fields from the source file — matching results,
 * user decisions and split structure live in {@see ImportState}.
 */
final class StatementLineDto
{
    public function __construct(
        public readonly \DateTimeImmutable $bookDate,
        public readonly \DateTimeImmutable $valueDate,
        public readonly string $amount,
        public readonly string $counterpartyName,
        public readonly ?string $counterpartyIban,
        public readonly string $purpose,
        public readonly ?string $endToEndId = null,
        public readonly ?string $mandateReference = null,
        public readonly ?string $creditorId = null,
    ) {
    }

    public function isIncoming(): bool
    {
        return (float) $this->amount >= 0.0;
    }

    /**
     * Deterministic fingerprint used to detect duplicate lines across imports.
     * Not reversible. Used only for comparison inside one bank account scope.
     */
    public function fingerprint(): string
    {
        $normalizedPurpose = strtolower(preg_replace('/\s+/', ' ', trim($this->purpose)) ?? '');

        return hash('sha256', implode('|', [
            $this->bookDate->format('Y-m-d'),
            $this->valueDate->format('Y-m-d'),
            $this->amount,
            $this->counterpartyIban ?? '',
            $normalizedPurpose,
            $this->endToEndId ?? '',
        ]));
    }
}
