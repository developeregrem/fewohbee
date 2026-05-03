<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\BankImport;

use App\Dto\BookingJournal\BankImport\ImportState;
use App\Entity\BankImportRule;

/**
 * Mutates one statement line according to a {@see BankImportRule}'s action.
 *
 * Three action modes:
 *  - "assign": set debit/credit accounts and a remark template.
 *  - "ignore": flag the line so it won't be committed.
 *  - "split":  break the line's amount into multiple parts, each with its
 *              own debit/credit accounts and remark.
 *
 * Split rows may define either a fixed amount, a percentage, a remainder, or
 * a dynamic amountSource="purpose_marker" plus marker text. Dynamic marker
 * amounts are read from the current line's purpose text on every import.
 *
 * Remark templates support a tiny placeholder language (no Twig, no eval):
 *   {counterparty} {purpose} {date} {invoiceNumber}
 */
final class RuleActionApplicator
{
    /**
     * @param array<string, mixed> $line
     */
    public function apply(BankImportRule $rule, array &$line): void
    {
        $action = $rule->getAction();
        $line['appliedRuleId'] = $rule->getId();

        switch ($action['mode'] ?? BankImportRule::ACTION_MODE_IGNORE) {
            case BankImportRule::ACTION_MODE_IGNORE:
                $line['isIgnored'] = true;
                $line['status'] = ImportState::LINE_STATUS_IGNORED;
                break;

            case BankImportRule::ACTION_MODE_ASSIGN:
                $this->applyAssign($action, $line);
                break;

            case BankImportRule::ACTION_MODE_SPLIT:
                $this->applySplit($action, $line);
                break;
        }
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $line
     */
    private function applyAssign(array $action, array &$line): void
    {
        if (isset($action['debitAccountId'])) {
            $line['userDebitAccountId'] = (int) $action['debitAccountId'];
        }
        if (isset($action['creditAccountId'])) {
            $line['userCreditAccountId'] = (int) $action['creditAccountId'];
        }
        if (isset($action['taxRateId'])) {
            $line['userTaxRateId'] = null !== $action['taxRateId'] ? (int) $action['taxRateId'] : null;
        }

        $remark = $this->renderTemplate($action['remarkTemplate'] ?? null, $line);
        if (null !== $remark) {
            $line['userRemark'] = $remark;
        }

        $line['status'] = ImportState::LINE_STATUS_READY;
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $line
     */
    private function applySplit(array $action, array &$line): void
    {
        $splitsConfig = $action['splits'] ?? [];
        if (!is_array($splitsConfig) || [] === $splitsConfig) {
            // Misconfigured rule — leave the line untouched at the action level
            // but flag the attempt so the UI can show "rule applied, no effect".
            return;
        }

        $totalAmount = abs((float) ($line['amount'] ?? 0));
        if (0.0 === $totalAmount) {
            return;
        }

        $resolved = [];
        $assigned = 0.0;
        $remainderIndex = null;

        foreach ($splitsConfig as $idx => $piece) {
            if (true === ($piece['remainder'] ?? false)) {
                $remainderIndex = $idx;
                $resolved[$idx] = ['amount' => 0.0, 'config' => $piece];
                continue;
            }

            if ('purpose_marker' === ($piece['amountSource'] ?? null)) {
                $marker = trim((string) ($piece['marker'] ?? ''));
                $share = $this->extractAmountAfterMarker((string) ($line['purpose'] ?? ''), $marker);
                if (null === $share) {
                    $this->markSplitRulePending($line, $marker);

                    return;
                }
            } elseif (isset($piece['amount'])) {
                $share = round((float) $piece['amount'], 2);
            } elseif (isset($piece['percent'])) {
                $share = round($totalAmount * ((float) $piece['percent'] / 100.0), 2);
            } else {
                continue;
            }

            $resolved[$idx] = ['amount' => $share, 'config' => $piece];
            $assigned += $share;
        }

        if (null !== $remainderIndex) {
            $resolved[$remainderIndex]['amount'] = round($totalAmount - $assigned, 2);
        }

        $isOutgoing = ((float) ($line['amount'] ?? 0)) < 0.0;
        $splits = [];
        foreach ($resolved as $entry) {
            $signed = $isOutgoing ? -$entry['amount'] : $entry['amount'];
            $splits[] = [
                'amount' => number_format($signed, 2, '.', ''),
                'debitAccountId' => isset($entry['config']['debitAccountId']) ? (int) $entry['config']['debitAccountId'] : null,
                'creditAccountId' => isset($entry['config']['creditAccountId']) ? (int) $entry['config']['creditAccountId'] : null,
                'taxRateId' => isset($entry['config']['taxRateId']) ? (int) $entry['config']['taxRateId'] : null,
                'remark' => $this->renderTemplate($entry['config']['remarkTemplate'] ?? null, $line),
            ];
        }

        $line['splits'] = $splits;
        unset($line['ruleWarning']);
        $line['status'] = ImportState::LINE_STATUS_READY;
    }

    /**
     * Reads the next decimal amount after a marker in free bank-purpose text.
     * Supports German and English separators, e.g. "12,30", "12.30",
     * "1.234,56", "1,234.56", integer amounts like "1790 Euro",
     * and an optional trailing minus sign.
     */
    private function extractAmountAfterMarker(string $purpose, string $marker): ?float
    {
        if ('' === trim($purpose) || '' === $marker) {
            return null;
        }

        $offset = stripos($purpose, $marker);
        if (false === $offset) {
            return null;
        }

        $tail = substr($purpose, $offset + strlen($marker));
        if (false === $tail || '' === $tail) {
            return null;
        }

        $amountPattern = '/(?<![\d.,])(?:([+-]?(?:\d{1,3}(?:[.,]\d{3})+[.,]\d{2}|\d+[.,]\d{2}))\s*-?(?![.,]\d)|([+-]?(?:\d{1,3}(?:[.,]\d{3})+|\d+))\s*-?(?=\s*(?:€|EUR\b|Euro\b|,|;|$))(?![.,]\d))/iu';
        if (1 !== preg_match($amountPattern, $tail, $matches)) {
            return null;
        }

        return $this->normalizeExtractedAmount($matches[1] ?: $matches[2]);
    }

    private function normalizeExtractedAmount(string $raw): ?float
    {
        $value = trim($raw);
        if ('' === $value) {
            return null;
        }

        $value = preg_replace('/[^\d,.\-+]/', '', $value) ?? '';
        if ('' === $value || '-' === $value || '+' === $value) {
            return null;
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if (false !== $lastComma || false !== $lastDot) {
            $separator = false !== $lastComma && false !== $lastDot
                ? ($lastComma > $lastDot ? ',' : '.')
                : (false !== $lastComma ? ',' : '.');
            $separatorPos = strrpos($value, $separator);
            $digitsAfterSeparator = false === $separatorPos ? 0 : strlen($value) - $separatorPos - 1;
            $isThousandsOnly = 3 === $digitsAfterSeparator
                && 1 === substr_count($value, $separator)
                && 1 === preg_match('/^[+-]?\d{1,3}[.,]\d{3}$/', $value);

            if ($isThousandsOnly) {
                $value = str_replace($separator, '', $value);
            } else {
                $decimal = false !== $lastComma && false !== $lastDot
                    ? ($lastComma > $lastDot ? ',' : '.')
                    : $separator;
                $thousands = ',' === $decimal ? '.' : ',';
                $value = str_replace($thousands, '', $value);
                $value = str_replace($decimal, '.', $value);
            }
        }

        return round(abs((float) $value), 2);
    }

    /**
     * @param array<string, mixed> $line
     */
    private function markSplitRulePending(array &$line, string $marker): void
    {
        $line['splits'] = [];
        $line['status'] = ImportState::LINE_STATUS_PENDING;
        $line['ruleWarning'] = [
            'key' => 'accounting.bank_import.rule.warning.split_marker_missing',
            'params' => ['%marker%' => $marker],
        ];
    }

    /**
     * @param array<string, mixed> $line
     */
    private function renderTemplate(?string $template, array $line): ?string
    {
        if (null === $template || '' === $template) {
            return null;
        }

        return strtr($template, [
            '{counterparty}'   => (string) ($line['counterpartyName'] ?? ''),
            '{purpose}'        => (string) ($line['purpose'] ?? ''),
            '{date}'           => (string) ($line['valueDate'] ?? $line['bookDate'] ?? ''),
            '{invoiceNumber}'  => (string) ($line['matchedInvoiceNumber'] ?? ''),
        ]);
    }
}
