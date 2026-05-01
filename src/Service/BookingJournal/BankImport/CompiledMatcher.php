<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\BankImport;

/**
 * Result of {@see InvoiceNumberPatternBuilder} — knows how to extract candidate
 * invoice numbers from arbitrary text (typically a bank statement purpose line).
 *
 * Built from one or more user-supplied example numbers. The user never sees regex.
 */
final class CompiledMatcher
{
    /**
     * @param list<string> $regexes  PCRE alternatives, each with capture group 1 returning the bare number
     * @param list<string> $samples  Original sample inputs (for diagnostics / UI preview)
     */
    public function __construct(
        public readonly array $regexes,
        public readonly array $samples,
        /**
         * If true, every digit-only candidate must additionally pass a strict
         * existence check against the invoice repository — otherwise we'd match
         * any 5+ digit number in the purpose line (VISA refs, mandate IDs, …).
         */
        public readonly bool $requiresStrictExistenceCheck = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->regexes;
    }

    /**
     * Extracts unique candidate invoice numbers from $haystack, in source order.
     *
     * @return list<string>
     */
    public function extractCandidates(string $haystack): array
    {
        if ('' === $haystack || $this->isEmpty()) {
            return [];
        }

        $found = [];
        foreach ($this->regexes as $regex) {
            if (preg_match_all($regex, $haystack, $matches)) {
                foreach ($matches[0] as $match) {
                    $candidate = trim((string) $match);
                    if ('' !== $candidate && !in_array($candidate, $found, true)) {
                        $found[] = $candidate;
                    }
                }
            }
        }

        return $found;
    }
}
