<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\BankImport;

use App\Entity\BankImportRule;

/**
 * Pure logic that decides whether one condition matches one statement line.
 *
 * Conditions are stored as plain arrays on {@see BankImportRule}:
 *   { field, operator, value }
 *
 * Field names map onto {@see \App\Dto\BookingJournal\BankImport\ImportState} line
 * arrays. Operators are case-insensitive for textual fields.
 */
final class RuleConditionEvaluator
{
    /**
     * @param array{field: string, operator: string, value: mixed} $condition
     * @param array<string, mixed>                                 $line
     */
    public function matches(array $condition, array $line): bool
    {
        $field = $condition['field'] ?? '';
        $operator = $condition['operator'] ?? '';
        $value = $condition['value'] ?? null;

        $resolved = $this->resolveField($field, $line);

        return match ($operator) {
            BankImportRule::CONDITION_OP_CONTAINS => $this->containsCi($resolved, (string) $value),
            BankImportRule::CONDITION_OP_NOT_CONTAINS => !$this->containsCi($resolved, (string) $value),
            BankImportRule::CONDITION_OP_EQUALS => $this->equalsCi($resolved, (string) $value),
            BankImportRule::CONDITION_OP_REGEX => $this->safeRegexMatch((string) $value, (string) $resolved),
            BankImportRule::CONDITION_OP_GT => (float) $resolved > (float) $value,
            BankImportRule::CONDITION_OP_LT => (float) $resolved < (float) $value,
            BankImportRule::CONDITION_OP_BETWEEN => $this->between((float) $resolved, $value),
            default => false,
        };
    }

    /**
     * Returns the line value to compare against. Strings for textual fields,
     * float for amount, and a normalised "in"/"out" token for direction.
     *
     * @param array<string, mixed> $line
     */
    private function resolveField(string $field, array $line): string|float
    {
        return match ($field) {
            BankImportRule::CONDITION_FIELD_COUNTERPARTY_NAME => (string) ($line['counterpartyName'] ?? ''),
            BankImportRule::CONDITION_FIELD_COUNTERPARTY_IBAN => (string) ($line['counterpartyIban'] ?? ''),
            BankImportRule::CONDITION_FIELD_PURPOSE => (string) ($line['purpose'] ?? ''),
            BankImportRule::CONDITION_FIELD_AMOUNT => (float) ($line['amount'] ?? 0),
            BankImportRule::CONDITION_FIELD_DIRECTION => ((float) ($line['amount'] ?? 0)) >= 0.0 ? 'in' : 'out',
            default => '',
        };
    }

    private function containsCi(string|float $haystack, string $needle): bool
    {
        if ('' === $needle) {
            return false;
        }

        return false !== stripos((string) $haystack, $needle);
    }

    private function equalsCi(string|float $haystack, string $value): bool
    {
        return 0 === strcasecmp((string) $haystack, $value);
    }

    private function safeRegexMatch(string $pattern, string $haystack): bool
    {
        if ('' === $pattern) {
            return false;
        }

        // Wrap raw patterns in delimiters if the user didn't. Always force the
        // case-insensitive flag — the rule editor is for hoteliers, not regex
        // power-users; "tibber" should match "Tibber".
        if (1 === preg_match('/^([\/#~]).+\1([imsxueADSUXJ]*)$/', $pattern, $m)) {
            if (false === stripos($m[2], 'i')) {
                $pattern .= 'i';
            }
        } else {
            $pattern = '/'.str_replace('/', '\\/', $pattern).'/i';
        }

        $result = @preg_match($pattern, $haystack);

        return 1 === $result;
    }

    private function between(float $value, mixed $bounds): bool
    {
        if (!is_array($bounds) || 2 !== count($bounds)) {
            return false;
        }

        [$lo, $hi] = array_values($bounds);

        return $value >= (float) $lo && $value <= (float) $hi;
    }
}
