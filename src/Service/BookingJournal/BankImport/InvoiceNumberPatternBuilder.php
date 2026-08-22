<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\BankImport;

use App\Service\InvoiceNumberPatternService;

/**
 * Compiles the configured invoice number ranges into a {@see CompiledMatcher} that can
 * extract candidate numbers from a bank statement's purpose text.
 *
 * Patterns come from the global default plus each branch's override, so the matcher
 * recognises exactly the formats the application issues — no inference, no guessing, and
 * nothing for the user to keep in sync by hand.
 */
final class InvoiceNumberPatternBuilder
{
    public function __construct(
        private readonly InvoiceNumberPatternService $patternService,
    ) {
    }

    /**
     * @param list<string> $patterns configured number ranges, e.g. ['RE-<year>-<number:4>']
     */
    public function buildFromPatterns(array $patterns): CompiledMatcher
    {
        $today = new \DateTimeImmutable();
        $regexes = [];
        $examples = [];

        foreach ($patterns as $raw) {
            $compiled = $this->patternService->tryCompile($raw);
            if (null === $compiled) {
                // An unusable pattern must never become a broken regex during import.
                continue;
            }

            $regex = $compiled->toRegex();
            if (!in_array($regex, $regexes, true)) {
                $regexes[] = $regex;
                $examples[] = $compiled->example($today);
            }
        }

        return new CompiledMatcher(regexes: $regexes, examples: $examples);
    }

    /**
     * Short, user-facing summary of what gets matched — the rendered examples rather than
     * the regexes, which users should never have to read.
     */
    public function describe(CompiledMatcher $matcher): string
    {
        return implode(', ', $matcher->examples);
    }
}
