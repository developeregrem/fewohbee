<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Dto;

/**
 * A compiled invoice number range, e.g. `RE-<year>-<number:4>`.
 *
 * Built by {@see \App\Service\InvoiceNumberPatternService::compile()} and used for three
 * jobs that must stay consistent with each other:
 *  - rendering the next number when an invoice is created,
 *  - producing the SQL LIKE expression that finds every number already issued in the
 *    same period, so the running sequence can be derived without a stored counter,
 *  - producing the regex the bank import uses to spot invoice numbers in a payment
 *    purpose text.
 *
 * Because all three come from the same segment list, what is matched is always exactly
 * what is issued.
 */
final class InvoiceNumberPattern
{
    public const KIND_LITERAL = 'literal';
    public const KIND_YEAR = 'year';
    public const KIND_YEAR2 = 'year2';
    public const KIND_MONTH = 'month';
    public const KIND_DAY = 'day';
    public const KIND_NUMBER = 'number';

    /**
     * Zero-padding used when `<number>` is written without an explicit width.
     *
     * A "no padding" default would force the bank import regex down to `\d{1,}`, which
     * matches every figure on a statement, and hoteliers do not think in padding widths.
     */
    public const DEFAULT_NUMBER_WIDTH = 4;

    /**
     * Widest padding a pattern may configure.
     */
    public const MAX_NUMBER_WIDTH = 12;

    /**
     * Date format characters for the placeholder kinds that render a date part.
     */
    private const DATE_FORMATS = [
        self::KIND_YEAR => 'Y',
        self::KIND_YEAR2 => 'y',
        self::KIND_MONTH => 'm',
        self::KIND_DAY => 'd',
    ];

    /**
     * Regex fragments per date placeholder. Month and day are constrained to real
     * calendar values so a number like `2026-9901` cannot be mistaken for one of ours.
     */
    private const DATE_REGEX = [
        self::KIND_YEAR => '\d{4}',
        self::KIND_YEAR2 => '\d{2}',
        self::KIND_MONTH => '(?:0[1-9]|1[0-2])',
        self::KIND_DAY => '(?:0[1-9]|[12]\d|3[01])',
    ];

    /**
     * How much longer than its configured padding a sequence may grow before the bank
     * import stops recognising it. Covers the 9999 -> 10000 overflow without loosening
     * the regex to the point where it matches arbitrary amounts on a statement.
     */
    private const NUMBER_REGEX_SLACK = 4;

    /**
     * @param list<array{kind: string, value: string, width: int}> $segments
     */
    public function __construct(
        public readonly string $pattern,
        public readonly array $segments,
    ) {
    }

    /**
     * Renders the number for a given date and running sequence, e.g. (2026-08-17, 42)
     * with `RE-<year>-<number:4>` yields `RE-2026-0042`. A sequence that outgrows the
     * configured padding is rendered in full rather than truncated.
     */
    public function render(\DateTimeInterface $date, int $sequence): string
    {
        $out = '';
        foreach ($this->segments as $segment) {
            $out .= match ($segment['kind']) {
                self::KIND_LITERAL => $segment['value'],
                self::KIND_NUMBER => str_pad((string) $sequence, $segment['width'], '0', STR_PAD_LEFT),
                default => $date->format(self::DATE_FORMATS[$segment['kind']]),
            };
        }

        return $out;
    }

    /**
     * The example shown in the settings UI: the first number of the given period.
     */
    public function example(\DateTimeInterface $date): string
    {
        return $this->render($date, 1);
    }

    /**
     * SQL LIKE expression covering every number issued in the same period, e.g.
     * `RE-2026-%`. Literal segments are escaped, so a pattern containing `%` or `_`
     * cannot turn into a wildcard and match unrelated invoices.
     *
     * Works regardless of where the sequence sits — `RE-<number:4>-<year>` yields
     * `RE-%-2026`.
     */
    public function likePattern(\DateTimeInterface $date): string
    {
        $out = '';
        foreach ($this->segments as $segment) {
            $out .= match ($segment['kind']) {
                self::KIND_LITERAL => $this->escapeForLike($segment['value']),
                self::KIND_NUMBER => '%',
                default => $date->format(self::DATE_FORMATS[$segment['kind']]),
            };
        }

        return $out;
    }

    /**
     * Regex for spotting numbers of this range inside free text (bank statement purpose).
     *
     * Uses explicit lookarounds instead of `\b`, because `\b` silently fails when the
     * pattern starts or ends with a non-word character, as in `<year>/<number:4>/`.
     */
    public function toRegex(): string
    {
        $parts = [];
        foreach ($this->segments as $segment) {
            $parts[] = match ($segment['kind']) {
                self::KIND_LITERAL => preg_quote($segment['value'], '/'),
                self::KIND_NUMBER => sprintf('\d{%d,%d}', $segment['width'], $segment['width'] + self::NUMBER_REGEX_SLACK),
                default => self::DATE_REGEX[$segment['kind']],
            };
        }

        return '/(?<![A-Za-z0-9])'.implode('', $parts).'(?![A-Za-z0-9])/i';
    }

    /**
     * Reads the running sequence back out of a concrete number, e.g. `RE-2026-0042`
     * yields 42. The date parts must match $date exactly, so numbers from another
     * period — and anything that simply does not fit the range — return null.
     */
    public function extractSequence(string $number, \DateTimeInterface $date): ?int
    {
        $parts = [];
        foreach ($this->segments as $segment) {
            $parts[] = match ($segment['kind']) {
                self::KIND_LITERAL => preg_quote($segment['value'], '/'),
                self::KIND_NUMBER => '(\d+)',
                default => preg_quote($date->format(self::DATE_FORMATS[$segment['kind']]), '/'),
            };
        }

        if (1 !== preg_match('/^'.implode('', $parts).'$/i', trim($number), $match)) {
            return null;
        }

        return (int) $match[1];
    }

    /**
     * Configured zero-padding of the running sequence.
     */
    public function numberWidth(): int
    {
        foreach ($this->segments as $segment) {
            if (self::KIND_NUMBER === $segment['kind']) {
                return $segment['width'];
            }
        }

        // Unreachable: compile() rejects patterns without a <number> placeholder.
        return self::DEFAULT_NUMBER_WIDTH;
    }

    /**
     * Escapes the LIKE metacharacters. MySQL's default escape character is the
     * backslash, so no ESCAPE clause is needed on the query side.
     */
    private function escapeForLike(string $literal): string
    {
        return addcslashes($literal, '\\%_');
    }
}
