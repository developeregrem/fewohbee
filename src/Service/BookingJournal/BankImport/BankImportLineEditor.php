<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\BankImport;

use App\Dto\BookingJournal\BankImport\ImportState;

/**
 * The single-field edits a user or an AI assistant can make to one line of an import draft, shared
 * by the bank import pages and the MCP tools so both follow the same rules.
 */
final class BankImportLineEditor
{
    public const FIELDS = ['debitAccountId', 'creditAccountId', 'taxRateId', 'remark', 'invoiceNumber', 'isIgnored'];

    /**
     * Duplicates of already imported lines are read-only unless the user forced their import.
     *
     * @param array<string, mixed> $line
     */
    public static function isEditable(array $line): bool
    {
        return true !== ($line['isDuplicate'] ?? false) || true === ($line['forceImportDuplicate'] ?? false);
    }

    /**
     * Sets one field of the line and re-derives its status.
     *
     * @param array<string, mixed> $line
     *
     * @throws \InvalidArgumentException for a field not in FIELDS
     */
    public static function setField(array &$line, string $field, mixed $value): void
    {
        match ($field) {
            'debitAccountId' => $line['userDebitAccountId'] = self::normalizeId($value),
            'creditAccountId' => $line['userCreditAccountId'] = self::normalizeId($value),
            'taxRateId' => $line['userTaxRateId'] = self::normalizeId($value),
            'remark' => $line['userRemark'] = self::cleanRemark($value),
            'invoiceNumber' => $line['userInvoiceNumber'] = self::cleanInvoiceNumber($value),
            'isIgnored' => $line['isIgnored'] = (bool) ((int) $value),
            default => throw new \InvalidArgumentException(\sprintf('Unknown field "%s".', $field)),
        };

        $line['status'] = ImportState::deriveLineStatus($line);
    }

    /** A positive id, or null for "not set". */
    public static function normalizeId(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    public static function cleanRemark(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim((string) $value);

        return '' === $value ? null : mb_substr($value, 0, 255);
    }

    public static function cleanInvoiceNumber(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim((string) $value);

        return '' === $value ? null : mb_substr($value, 0, 50);
    }
}
