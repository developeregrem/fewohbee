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

    /**
     * Merges several parser outputs into one. The widest period span and
     * concatenated lines/warnings win; if the inputs reference more than one
     * source IBAN, a {@see MultipleSourceAccountsException} is raised so the
     * caller can stop the import early.
     *
     * @param list<self> $results
     */
    public static function merge(array $results): self
    {
        $lines = [];
        $warnings = [];
        $ibans = [];
        $periodFrom = null;
        $periodTo = null;

        foreach ($results as $result) {
            array_push($lines, ...$result->lines);
            array_push($warnings, ...$result->warnings);

            if (null !== $result->sourceIban) {
                $ibans[$result->sourceIban] = true;
            }

            $periodFrom = self::minDate($periodFrom, $result->periodFrom);
            $periodTo = self::maxDate($periodTo, $result->periodTo);
        }

        if (count($ibans) > 1) {
            throw new MultipleSourceAccountsException();
        }

        return new self(
            lines: $lines,
            sourceIban: array_key_first($ibans),
            periodFrom: $periodFrom,
            periodTo: $periodTo,
            warnings: $warnings,
        );
    }

    public static function minDate(?\DateTimeImmutable $left, ?\DateTimeImmutable $right): ?\DateTimeImmutable
    {
        if (null === $left) {
            return $right;
        }
        if (null === $right) {
            return $left;
        }

        return $right < $left ? $right : $left;
    }

    public static function maxDate(?\DateTimeImmutable $left, ?\DateTimeImmutable $right): ?\DateTimeImmutable
    {
        if (null === $left) {
            return $right;
        }
        if (null === $right) {
            return $left;
        }

        return $right > $left ? $right : $left;
    }
}
