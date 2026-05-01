<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\BankImport;

/**
 * Turns user-supplied example invoice numbers (e.g. "RE-12345", "2026-0001",
 * "12345") into a {@see CompiledMatcher} that can extract candidate numbers
 * from a bank statement's purpose text.
 *
 * The user never sees regex. They just type how their invoice numbers look,
 * we infer the structure: letters become fixed (case-insensitive) literals,
 * digit blocks become flexible-length placeholders, and characters like
 * ``-_/.`` become separator literals.
 *
 * Pure-numeric samples (``"12345"``) trigger the strict-existence flag so the
 * caller knows it must verify candidates against the actual invoice table —
 * otherwise we'd match any random number on a statement.
 */
final class InvoiceNumberPatternBuilder
{
    /**
     * @param list<string> $samples
     */
    public function buildFromSamples(array $samples): CompiledMatcher
    {
        $cleanSamples = [];
        foreach ($samples as $raw) {
            $value = trim((string) $raw);
            if ('' !== $value) {
                $cleanSamples[] = $value;
            }
        }

        if ([] === $cleanSamples) {
            return new CompiledMatcher([], [], false);
        }

        $regexes = [];
        $strictNeeded = false;

        foreach ($cleanSamples as $sample) {
            $tokens = $this->tokenize($sample);
            if ([] === $tokens) {
                continue;
            }

            $isPure = $this->isPureDigitsOnly($tokens);
            if ($isPure) {
                $strictNeeded = true;
            }

            // Pure-numeric samples ("12345") get tight digit bounds so they
            // don't accidentally match every number on a statement (booking
            // counts, fees, …). Mixed samples ("RE-12345") keep loose bounds
            // because their literal prefix already disambiguates.
            $regexes[] = $this->compileTokens($tokens, $isPure);
        }

        // Deduplicate while preserving order.
        $regexes = array_values(array_unique($regexes));

        return new CompiledMatcher(
            regexes: $regexes,
            samples: $cleanSamples,
            requiresStrictExistenceCheck: $strictNeeded,
        );
    }

    /**
     * Returns a short, user-friendly description of what gets matched.
     * Shown in the settings UI so people can sanity-check their inputs.
     */
    public function describe(CompiledMatcher $matcher): string
    {
        if ($matcher->isEmpty()) {
            return '';
        }

        return implode(', ', array_map([$this, 'describeSample'], $matcher->samples));
    }

    /**
     * Synthesises example matches for a single sample — useful for preview UI.
     *
     * @return list<string>
     */
    public function exemplifyMatches(string $sample): array
    {
        $tokens = $this->tokenize(trim($sample));
        if ([] === $tokens) {
            return [];
        }

        $minVariant = '';
        $observedVariant = '';
        $maxVariant = '';

        foreach ($tokens as $token) {
            switch ($token['kind']) {
                case 'letters':
                    $minVariant      .= $token['value'];
                    $observedVariant .= $token['value'];
                    $maxVariant      .= $token['value'];
                    break;
                case 'separator':
                    $minVariant      .= $token['value'];
                    $observedVariant .= $token['value'];
                    $maxVariant      .= $token['value'];
                    break;
                case 'digits':
                    $observedLen = strlen($token['value']);
                    $minVariant      .= '1';
                    $observedVariant .= $token['value'];
                    $maxVariant      .= str_repeat('9', $observedLen + 2);
                    break;
            }
        }

        return array_values(array_unique([$minVariant, $observedVariant, $maxVariant]));
    }

    // ── Internals ─────────────────────────────────────────────────────

    /**
     * @return list<array{kind: string, value: string}>
     */
    private function tokenize(string $sample): array
    {
        if ('' === $sample) {
            return [];
        }

        $tokens = [];
        $length = strlen($sample);
        $i = 0;

        while ($i < $length) {
            $char = $sample[$i];

            if (ctype_alpha($char)) {
                $start = $i;
                while ($i < $length && ctype_alpha($sample[$i])) {
                    ++$i;
                }
                $tokens[] = ['kind' => 'letters', 'value' => substr($sample, $start, $i - $start)];
                continue;
            }

            if (ctype_digit($char)) {
                $start = $i;
                while ($i < $length && ctype_digit($sample[$i])) {
                    ++$i;
                }
                $tokens[] = ['kind' => 'digits', 'value' => substr($sample, $start, $i - $start)];
                continue;
            }

            if (in_array($char, ['-', '_', '/', '.', ' '], true)) {
                $tokens[] = ['kind' => 'separator', 'value' => $char];
                ++$i;
                continue;
            }

            // Unknown character — skip silently rather than fail. This keeps
            // the inference forgiving for stray punctuation in samples.
            ++$i;
        }

        return $tokens;
    }

    /**
     * @param list<array{kind: string, value: string}> $tokens
     */
    private function isPureDigitsOnly(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ('digits' !== $token['kind']) {
                return false;
            }
        }

        return [] !== $tokens;
    }

    /**
     * @param list<array{kind: string, value: string}> $tokens
     */
    /**
     * @param list<array{kind: string, value: string}> $tokens
     */
    private function compileTokens(array $tokens, bool $tightDigitBounds = false): string
    {
        $parts = [];

        foreach ($tokens as $token) {
            switch ($token['kind']) {
                case 'letters':
                    // Normalise to upper-case so "re" and "RE" yield the same
                    // regex string and can be deduplicated. The /i flag still
                    // matches both cases at runtime.
                    $parts[] = preg_quote(strtoupper($token['value']), '/');
                    break;
                case 'separator':
                    $parts[] = preg_quote($token['value'], '/');
                    break;
                case 'digits':
                    $observedLen = strlen($token['value']);
                    if ($tightDigitBounds) {
                        // Pure-numeric: stay close to observed length.
                        $minLen = $observedLen;
                        $maxLen = $observedLen + 2;
                    } else {
                        // Mixed: be liberal — literal context prevents drift.
                        $minLen = 1;
                        $maxLen = $observedLen + 4;
                    }
                    $parts[] = sprintf('\d{%d,%d}', $minLen, $maxLen);
                    break;
            }
        }

        // Word boundaries on both sides keep "RE-12345" out of "CORE-123456789".
        return '/\b'.implode('', $parts).'\b/i';
    }

    private function describeSample(string $sample): string
    {
        $tokens = $this->tokenize(trim($sample));
        if ([] === $tokens) {
            return $sample;
        }

        $pieces = [];
        foreach ($tokens as $token) {
            switch ($token['kind']) {
                case 'letters':
                    $pieces[] = '"'.$token['value'].'"';
                    break;
                case 'separator':
                    $pieces[] = '"'.$token['value'].'"';
                    break;
                case 'digits':
                    $pieces[] = strlen($token['value']).' Ziffern';
                    break;
            }
        }

        return implode(' ', $pieces);
    }
}
