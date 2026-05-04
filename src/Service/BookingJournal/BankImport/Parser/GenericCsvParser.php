<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\BankImport\Parser;

use App\Dto\BookingJournal\BankImport\ParseResult;
use App\Dto\BookingJournal\BankImport\StatementLineDto;
use App\Entity\BankCsvProfile;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reads any CSV bank statement according to a {@see BankCsvProfile}.
 *
 * The profile configures everything that varies between banks: delimiter,
 * encoding, how many vorspann lines to skip before the column header, the
 * column-to-field mapping, the date format and the amount locale.
 *
 * No bank-specific knowledge is hard-coded here; banks are added by creating
 * profiles, not by adding code.
 */
final class GenericCsvParser implements ParserInterface
{
    public const FORMAT_KEY = 'csv_generic';

    public function __construct(
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function getFormatKey(): string
    {
        return self::FORMAT_KEY;
    }

    public function supportsMultipleFiles(): bool
    {
        return false;
    }

    public function parse(\SplFileInfo $file, ?BankCsvProfile $profile): ParseResult
    {
        if (null === $profile) {
            throw new \InvalidArgumentException($this->trans('accounting.bank_import.parser.error.profile_required'));
        }

        $rows = $this->readAllRows($file, $profile);

        $sourceIban = $this->extractIban($rows, $profile);
        [$periodFrom, $periodTo] = $this->extractPeriod($rows, $profile);

        $dataStart = $profile->getHeaderSkip() + ($profile->hasHeaderRow() ? 1 : 0);
        $columnMap = $profile->getColumnMap();
        $this->assertRequiredColumns($columnMap, $profile->getDirectionMode());

        $lines = [];
        $warnings = [];

        for ($i = $dataStart, $n = count($rows); $i < $n; ++$i) {
            $row = $rows[$i];
            if ($this->isEmptyRow($row)) {
                continue;
            }

            // Banks often append a trailing balance row like "Kontostand;…"
            // that shares the data layout but isn't a transaction. Skip
            // silently if the date column carries no digits at all.
            if (!$this->looksLikeDataRow($row, $columnMap)) {
                continue;
            }

            try {
                $lines[] = $this->buildLine($row, $columnMap, $profile);
            } catch (\Throwable $e) {
                $warnings[] = $this->trans('accounting.bank_import.parser.warning.row_skipped', [
                    '%line%' => $i + 1,
                    '%message%' => $e->getMessage(),
                ]);
            }
        }

        return new ParseResult($lines, $sourceIban, $periodFrom, $periodTo, $warnings);
    }

    /**
     * @return list<list<string>>
     */
    private function readAllRows(\SplFileInfo $file, BankCsvProfile $profile): array
    {
        $path = $file->getPathname();
        $content = file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException($this->trans('accounting.bank_import.parser.error.file_read_failed', [
                '%path%' => $path,
            ]));
        }

        $encoding = $profile->getEncoding();
        if ('UTF-8' !== strtoupper($encoding)) {
            $converted = @mb_convert_encoding($content, 'UTF-8', $encoding);
            if (false !== $converted) {
                $content = $converted;
            }
        }

        // Strip UTF-8 BOM if present.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $stream = fopen('php://memory', 'r+');
        if (false === $stream) {
            throw new \RuntimeException($this->trans('accounting.bank_import.parser.error.temp_stream_failed'));
        }
        fwrite($stream, $content);
        rewind($stream);

        $rows = [];
        while (false !== ($row = fgetcsv($stream, 0, $profile->getDelimiter(), $profile->getEnclosure(), '\\'))) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    /**
     * @param array<string, int> $columnMap
     */
    private function buildLine(array $row, array $columnMap, BankCsvProfile $profile): StatementLineDto
    {
        $bookDate = $this->parseDate($this->cell($row, $columnMap['bookDate']), $profile->getDateFormat());
        $valueDate = isset($columnMap['valueDate'])
            ? $this->parseDate($this->cell($row, $columnMap['valueDate']), $profile->getDateFormat())
            : $bookDate;

        $amount = $this->parseAmount($row, $columnMap, $profile);

        $counterpartyName = isset($columnMap['counterpartyName'])
            ? trim($this->cell($row, $columnMap['counterpartyName']))
            : '';

        $counterpartyIban = isset($columnMap['counterpartyIban'])
            ? $this->normalizeIban($this->cell($row, $columnMap['counterpartyIban']))
            : null;

        $purpose = isset($columnMap['purpose'])
            ? trim($this->cell($row, $columnMap['purpose']))
            : '';

        return new StatementLineDto(
            bookDate: $bookDate,
            valueDate: $valueDate,
            amount: $amount,
            counterpartyName: $counterpartyName,
            counterpartyIban: $counterpartyIban,
            purpose: $purpose,
            endToEndId: $this->optional($row, $columnMap, 'endToEndId'),
            mandateReference: $this->optional($row, $columnMap, 'mandateReference'),
            creditorId: $this->optional($row, $columnMap, 'creditorId'),
        );
    }

    private function parseDate(string $raw, string $format): \DateTimeImmutable
    {
        $raw = trim($raw);
        $date = \DateTimeImmutable::createFromFormat('!'.$format, $raw);
        if (false === $date) {
            throw new \InvalidArgumentException($this->trans('accounting.bank_import.parser.error.invalid_date', [
                '%value%' => $raw,
                '%format%' => $format,
            ]));
        }

        if ((int) $date->format('Y') < 100 && 1 === preg_match('/(\d{2})\D*$/', $raw, $matches)) {
            $year = (int) $matches[1];
            $date = $date->setDate(2000 + $year, (int) $date->format('n'), (int) $date->format('j'));
        }

        return $date;
    }

    /**
     * Returns the amount as a fixed-point string with two decimals, e.g. "-41.98".
     *
     * @param array<string, int> $columnMap
     */
    private function parseAmount(array $row, array $columnMap, BankCsvProfile $profile): string
    {
        if (BankCsvProfile::DIRECTION_SEPARATE_COLUMNS === $profile->getDirectionMode()) {
            $debit = $this->normalizeAmount(
                $this->cell($row, $columnMap['amountDebit'] ?? -1),
                $profile,
            );
            $credit = $this->normalizeAmount(
                $this->cell($row, $columnMap['amountCredit'] ?? -1),
                $profile,
            );

            // Only one side carries a value per row; debit becomes negative.
            if ('' !== $debit && '0.00' !== $debit) {
                return number_format(-1 * (float) $debit, 2, '.', '');
            }

            return '' === $credit ? '0.00' : $credit;
        }

        $value = $this->normalizeAmount($this->cell($row, $columnMap['amount']), $profile);

        return '' === $value ? '0.00' : $value;
    }

    private function normalizeAmount(string $raw, BankCsvProfile $profile): string
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return '';
        }

        $thousands = $profile->getAmountThousandsSeparator();
        if (null !== $thousands && '' !== $thousands) {
            $raw = str_replace($thousands, '', $raw);
        }
        $raw = str_replace($profile->getAmountDecimalSeparator(), '.', $raw);
        $raw = preg_replace('/[^\d.\-+]/', '', $raw) ?? '';

        if ('' === $raw || '-' === $raw || '+' === $raw) {
            throw new \InvalidArgumentException($this->trans('accounting.bank_import.parser.error.amount_parse_failed'));
        }

        return number_format((float) $raw, 2, '.', '');
    }

    private function normalizeIban(string $raw): ?string
    {
        $raw = strtoupper(preg_replace('/\s+/', '', trim($raw)) ?? '');

        return '' === $raw ? null : $raw;
    }

    private function cell(array $row, int $index): string
    {
        return (string) ($row[$index] ?? '');
    }

    /**
     * @param array<string, int> $columnMap
     */
    private function optional(array $row, array $columnMap, string $field): ?string
    {
        if (!isset($columnMap[$field])) {
            return null;
        }

        $value = trim($this->cell($row, $columnMap[$field]));

        return '' === $value ? null : $value;
    }

    /**
     * @param array<string, int> $columnMap
     */
    private function assertRequiredColumns(array $columnMap, string $directionMode): void
    {
        if (!isset($columnMap['bookDate'])) {
            throw new \InvalidArgumentException($this->trans('accounting.bank_import.parser.error.required_column', [
                '%field%' => 'bookDate',
            ]));
        }

        if (BankCsvProfile::DIRECTION_SEPARATE_COLUMNS === $directionMode) {
            if (!isset($columnMap['amountDebit'], $columnMap['amountCredit'])) {
                throw new \InvalidArgumentException($this->trans('accounting.bank_import.parser.error.required_separate_columns'));
            }

            return;
        }

        if (!isset($columnMap['amount'])) {
            throw new \InvalidArgumentException($this->trans('accounting.bank_import.parser.error.required_column', [
                '%field%' => 'amount',
            ]));
        }
    }

    /**
     * @param list<list<string>> $rows
     */
    private function extractIban(array $rows, BankCsvProfile $profile): ?string
    {
        $line = $profile->getIbanSourceLine();
        if (null === $line || !isset($rows[$line])) {
            return null;
        }

        foreach ($rows[$line] as $cell) {
            $candidate = $this->normalizeIban((string) $cell);
            if (null !== $candidate && preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{4,30}$/', $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<list<string>> $rows
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function extractPeriod(array $rows, BankCsvProfile $profile): array
    {
        $line = $profile->getPeriodSourceLine();
        if (null === $line || !isset($rows[$line])) {
            return [null, null];
        }

        $haystack = implode(' ', array_map('strval', $rows[$line]));
        if (!preg_match_all('/(\d{1,2}\.\d{1,2}\.\d{2,4})/', $haystack, $matches)) {
            return [null, null];
        }

        $dates = $matches[1];
        if (count($dates) < 2) {
            return [null, null];
        }

        $format = $this->guessDateFormat($dates[0]);
        $from = \DateTimeImmutable::createFromFormat('!'.$format, $dates[0]);
        $to = \DateTimeImmutable::createFromFormat('!'.$format, $dates[1]);

        return [$from ?: null, $to ?: null];
    }

    private function guessDateFormat(string $sample): string
    {
        // Look at the trailing year segment: 4 digits → full year, 2 → short.
        // Day and month parts may be unpadded ("1.4.2025") so we use j.n which
        // accepts both forms.
        if (1 === preg_match('/\.(\d{2,4})$/', $sample, $matches)) {
            return 4 === strlen($matches[1]) ? 'j.n.Y' : 'j.n.y';
        }

        return 'j.n.Y';
    }

    /**
     * @param array<string, int> $columnMap
     */
    private function looksLikeDataRow(array $row, array $columnMap): bool
    {
        $dateCell = trim($this->cell($row, $columnMap['bookDate']));
        if ('' === $dateCell) {
            return false;
        }

        // A real date contains digits. Trailer rows like "Kontostand;…" or
        // "Saldo neu" don't, so we can skip them silently.
        return 1 === preg_match('/\d/', $dateCell);
    }

    private function isEmptyRow(array $row): bool
    {
        if (1 === count($row) && '' === trim((string) $row[0])) {
            return true;
        }
        foreach ($row as $cell) {
            if ('' !== trim((string) $cell)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator?->trans($key, $parameters) ?? $key;
    }
}
