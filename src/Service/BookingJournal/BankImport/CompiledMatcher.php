<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\BankImport;

/**
 * Result of {@see InvoiceNumberPatternBuilder} — knows how to extract candidate
 * invoice numbers from arbitrary text (typically a bank statement purpose line).
 *
 * Built from the configured invoice number ranges, so what it recognises is exactly
 * what the application issues. The user never sees a regex.
 */
final class CompiledMatcher
{
    /**
     * @param list<string> $regexes  PCRE alternatives; the whole match is the candidate number
     * @param list<string> $examples One rendered example per pattern, for the settings UI
     */
    public function __construct(
        public readonly array $regexes,
        public readonly array $examples,
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
