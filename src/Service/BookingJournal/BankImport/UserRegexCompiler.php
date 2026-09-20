<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\BankImport;

/**
 * Turns the regular expressions typed into the rule editor into patterns that
 * preg_* accepts.
 *
 * Hoteliers write "Rechnung\s+(\d+)", not "/Rechnung\s+(\d+)/iu" — so a raw
 * pattern gets delimiters plus the case-insensitive and unicode flags. A
 * pattern that already carries its own delimiters is left untouched.
 */
final class UserRegexCompiler
{
    /**
     * @return string|null the ready-to-use pattern, or null when $pattern is empty
     */
    public function compile(string $pattern): ?string
    {
        if ('' === $pattern) {
            return null;
        }

        if (1 === preg_match('/^([\/#~]).+\1([imsxueADSUXJ]*)$/', $pattern)) {
            return $pattern;
        }

        return '/'.str_replace('/', '\\/', $pattern).'/iu';
    }

    /**
     * Whether the pattern compiles at all. Used by the rule editor to reject a
     * typo while it is being saved, instead of letting it fail silently on the
     * next import.
     */
    public function isValid(string $pattern): bool
    {
        $compiled = $this->compile($pattern);

        return null !== $compiled && false !== @preg_match($compiled, '');
    }
}
