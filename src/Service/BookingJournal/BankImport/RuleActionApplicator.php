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
 * a dynamic amountSource="purpose_marker"/"purpose_regex". Dynamic amounts
 * are read from the current line's purpose text on every import.
 *
 * Rule actions may also extract one external invoice/document number from the
 * purpose text. That number is line-wide, including split bookings, and is
 * intentionally separate from the application's internal InvoiceMatcher.
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

        $mode = $action['mode'] ?? BankImportRule::ACTION_MODE_IGNORE;
        if (BankImportRule::ACTION_MODE_IGNORE !== $mode && !$this->applyInvoiceNumberExtraction($action, $line)) {
            return;
        }

        switch ($mode) {
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
        unset($line['ruleWarning']);
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
                    $this->markSplitRulePending(
                        $line,
                        'accounting.bank_import.rule.warning.split_marker_missing',
                        ['%marker%' => $marker],
                    );

                    return;
                }
            } elseif ('purpose_regex' === ($piece['amountSource'] ?? null)) {
                $pattern = trim((string) ($piece['pattern'] ?? ''));
                $share = $this->extractAmountByRegex((string) ($line['purpose'] ?? ''), $pattern);
                if (false === $share) {
                    $this->markSplitRulePending(
                        $line,
                        'accounting.bank_import.rule.warning.split_regex_invalid',
                        ['%pattern%' => $pattern],
                    );

                    return;
                }
                if (null === $share) {
                    $this->markSplitRulePending(
                        $line,
                        'accounting.bank_import.rule.warning.split_regex_missing',
                        ['%pattern%' => $pattern],
                    );

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

    /**
     * @return float|null|false false means invalid regex, null means no match
     */
    private function extractAmountByRegex(string $purpose, string $pattern): float|null|false
    {
        $regex = $this->normalizeUserRegex($pattern);
        if (null === $regex) {
            return false;
        }

        $result = @preg_match($regex, $purpose, $matches);
        if (false === $result) {
            return false;
        }
        if (0 === $result) {
            return null;
        }

        foreach (array_slice($matches, 1) as $capture) {
            if (!is_string($capture)) {
                continue;
            }

            $amount = $this->normalizeExtractedAmount($capture);
            if (null !== $amount) {
                return $amount;
            }
        }

        return $this->normalizeExtractedAmount((string) ($matches[0] ?? ''));
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $line
     */
    private function applyInvoiceNumberExtraction(array $action, array &$line): bool
    {
        $config = $action['invoiceNumberExtraction'] ?? null;
        if (!is_array($config)) {
            return true;
        }

        $mode = (string) ($config['mode'] ?? 'none');
        if ('none' === $mode || '' === $mode) {
            return true;
        }

        $purpose = (string) ($line['purpose'] ?? '');
        if ('marker' === $mode) {
            $marker = trim((string) ($config['marker'] ?? ''));
            $invoiceNumber = $this->extractInvoiceNumberAfterMarker($purpose, $marker);
            if (null === $invoiceNumber) {
                $this->markInvoiceNumberRulePending(
                    $line,
                    'accounting.bank_import.rule.warning.invoice_marker_missing',
                    ['%marker%' => $marker],
                );

                return false;
            }

            $line['userInvoiceNumber'] = $invoiceNumber;

            return true;
        }

        if ('regex' === $mode) {
            $pattern = trim((string) ($config['pattern'] ?? ''));
            $invoiceNumber = $this->extractInvoiceNumberByRegex($purpose, $pattern);
            if (false === $invoiceNumber) {
                $this->markInvoiceNumberRulePending(
                    $line,
                    'accounting.bank_import.rule.warning.invoice_regex_invalid',
                    ['%pattern%' => $pattern],
                );

                return false;
            }
            if (null === $invoiceNumber) {
                $this->markInvoiceNumberRulePending(
                    $line,
                    'accounting.bank_import.rule.warning.invoice_regex_missing',
                    ['%pattern%' => $pattern],
                );

                return false;
            }

            $line['userInvoiceNumber'] = $invoiceNumber;

            return true;
        }

        return true;
    }

    private function extractInvoiceNumberAfterMarker(string $purpose, string $marker): ?string
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

        $pattern = '/^\s*(?:(?:nr\.?|nummer|no\.?|#)\s*)?[:\-#\s]*([[:alnum:]][[:alnum:].\/_-]{1,49})/iu';
        if (1 !== preg_match($pattern, $tail, $matches)) {
            return null;
        }

        return $this->cleanInvoiceNumber($matches[1] ?? null);
    }

    /**
     * @return string|null|false false means invalid regex, null means no match
     */
    private function extractInvoiceNumberByRegex(string $purpose, string $pattern): string|null|false
    {
        $regex = $this->normalizeUserRegex($pattern);
        if (null === $regex) {
            return false;
        }

        $result = @preg_match($regex, $purpose, $matches);
        if (false === $result) {
            return false;
        }
        if (0 === $result) {
            return null;
        }

        return $this->cleanInvoiceNumber($matches[1] ?? $matches[0] ?? null) ?? false;
    }

    private function normalizeUserRegex(string $pattern): ?string
    {
        if ('' === $pattern) {
            return null;
        }

        if (1 === preg_match('/^([\/#~]).+\1([imsxueADSUXJ]*)$/', $pattern)) {
            return $pattern;
        }

        return '/'.str_replace('/', '\\/', $pattern).'/iu';
    }

    private function cleanInvoiceNumber(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim((string) $value);
        $value = trim($value, " \t\n\r\0\x0B.,;:");

        return '' === $value ? null : mb_substr($value, 0, 50);
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
    private function markSplitRulePending(array &$line, string $key, array $params): void
    {
        $line['splits'] = [];
        $line['status'] = ImportState::LINE_STATUS_PENDING;
        $line['ruleWarning'] = [
            'key' => $key,
            'params' => $params,
        ];
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, string> $params
     */
    private function markInvoiceNumberRulePending(array &$line, string $key, array $params): void
    {
        $line['splits'] = [];
        $line['status'] = ImportState::LINE_STATUS_PENDING;
        $line['ruleWarning'] = [
            'key' => $key,
            'params' => $params,
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
            '{invoiceNumber}'  => (string) (($line['userInvoiceNumber'] ?? null) ?: ($line['matchedInvoiceNumber'] ?? '')),
        ]);
    }
}
