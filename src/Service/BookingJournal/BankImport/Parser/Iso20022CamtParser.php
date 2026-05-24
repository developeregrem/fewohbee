<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\BankImport\Parser;

use App\Dto\BookingJournal\BankImport\ParseResult;
use App\Dto\BookingJournal\BankImport\StatementLineDto;
use App\Entity\BankCsvProfile;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Parses ISO 20022 camt.052 account reports and camt.053 statements.
 *
 * The parser intentionally traverses by local element name instead of binding
 * to one namespace version. The stable camt core is enough for the booking
 * journal import and keeps newer compatible namespace versions importable.
 */
final class Iso20022CamtParser implements ParserInterface
{
    public const FORMAT_KEY = 'iso20022_camt';

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
        return true;
    }

    public function parse(\SplFileInfo $file, ?BankCsvProfile $profile): ParseResult
    {
        unset($profile);

        $document = $this->loadXml($file);
        [$messageType, $version] = $this->detectMessage($document);
        $warnings = $this->versionWarnings($messageType, $version, $file);

        // camt.052 wraps account reports in Rpt, camt.053 wraps statements in Stmt.
        // The contained Ntry records are deliberately processed through one path.
        $containers = match ($messageType) {
            '052' => $this->xpath($document, '/*[local-name()="Document"]/*[local-name()="BkToCstmrAcctRpt"]/*[local-name()="Rpt"]'),
            '053' => $this->xpath($document, '/*[local-name()="Document"]/*[local-name()="BkToCstmrStmt"]/*[local-name()="Stmt"]'),
            default => throw new \RuntimeException($this->trans('accounting.bank_import.parser.error.camt_unsupported_message')),
        };

        if ([] === $containers) {
            throw new \RuntimeException($this->trans('accounting.bank_import.parser.error.camt_no_statements'));
        }

        $lines = [];
        $ibans = [];
        $periodFrom = null;
        $periodTo = null;
        $skippedNonBooked = 0;

        foreach ($containers as $container) {
            // All selected files must describe the same account; the controller
            // also checks this after merging multiple camt files into one draft.
            $sourceIban = $this->normalizeIban($this->textPath($container, ['Acct', 'Id', 'IBAN']));
            if (null !== $sourceIban) {
                $ibans[$sourceIban] = true;
            }

            // Some banks provide a statement/report period, others only dates
            // on the entries. We collect both and keep the widest range.
            [$containerFrom, $containerTo] = $this->extractPeriod($container);
            $periodFrom = ParseResult::minDate($periodFrom, $containerFrom);
            $periodTo = ParseResult::maxDate($periodTo, $containerTo);

            foreach ($this->children($container, 'Ntry') as $entry) {
                // camt.052 may also carry pending or informational movements;
                // only BOOK entries are safe to post to the journal.
                if ('052' === $messageType && 'BOOK' !== $this->entryStatus($entry)) {
                    ++$skippedNonBooked;
                    continue;
                }

                try {
                    foreach ($this->buildEntryLines($entry) as $line) {
                        $periodFrom = ParseResult::minDate($periodFrom, $line->bookDate);
                        $periodTo = ParseResult::maxDate($periodTo, $line->bookDate);
                        $lines[] = $line;
                    }
                } catch (\Throwable $e) {
                    $warnings[] = $this->trans('accounting.bank_import.parser.warning.entry_skipped', [
                        '%file%' => $file->getBasename(),
                        '%message%' => $e->getMessage(),
                    ]);
                }
            }
        }

        if (count($ibans) > 1) {
            throw new \RuntimeException($this->trans('accounting.bank_import.parser.error.camt_multiple_accounts'));
        }

        if ($skippedNonBooked > 0) {
            $warnings[] = $this->trans('accounting.bank_import.parser.warning.camt_non_booked_skipped', [
                '%count%' => $skippedNonBooked,
                '%file%' => $file->getBasename(),
            ]);
        }

        return new ParseResult(
            lines: $lines,
            sourceIban: array_key_first($ibans),
            periodFrom: $periodFrom,
            periodTo: $periodTo,
            warnings: $warnings,
        );
    }

    private function loadXml(\SplFileInfo $file): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $xml = simplexml_load_file($file->getPathname(), \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$xml instanceof \SimpleXMLElement) {
            $message = $errors[0]->message ?? $this->trans('accounting.bank_import.parser.error.camt_invalid_xml');

            throw new \RuntimeException(trim($message));
        }

        return $xml;
    }

    /**
     * @return array{0: string, 1: ?int}
     */
    private function detectMessage(\SimpleXMLElement $document): array
    {
        // Real exports are not consistent: some use the camt namespace as the
        // default namespace, others declare it as a prefix on Document.
        foreach ($document->getDocNamespaces(false) as $namespace) {
            if (1 === preg_match('/camt\.(052|053)\.001\.(\d{2})/', $namespace, $matches)) {
                return [$matches[1], (int) $matches[2]];
            }
        }

        // If the namespace is missing or non-standard, still detect by the
        // ISO 20022 message root and report an "unknown version" warning.
        if ([] !== $this->xpath($document, '/*[local-name()="Document"]/*[local-name()="BkToCstmrAcctRpt"]')) {
            return ['052', null];
        }
        if ([] !== $this->xpath($document, '/*[local-name()="Document"]/*[local-name()="BkToCstmrStmt"]')) {
            return ['053', null];
        }

        throw new \RuntimeException($this->trans('accounting.bank_import.parser.error.camt_unsupported_message'));
    }

    /**
     * @return list<string>
     */
    private function versionWarnings(string $messageType, ?int $version, \SplFileInfo $file): array
    {
        if (null === $version) {
            return [$this->trans('accounting.bank_import.parser.warning.camt_unknown_version', [
                '%file%' => $file->getBasename(),
            ])];
        }

        if ($version < 8) {
            throw new \RuntimeException($this->trans('accounting.bank_import.parser.error.camt_version_too_old', [
                '%type%' => $messageType,
                '%version%' => sprintf('%02d', $version),
            ]));
        }

        if ($version > 14) {
            return [$this->trans('accounting.bank_import.parser.warning.camt_newer_version', [
                '%type%' => $messageType,
                '%version%' => sprintf('%02d', $version),
            ])];
        }

        return [];
    }

    /**
     * @return list<StatementLineDto>
     */
    private function buildEntryLines(\SimpleXMLElement $entry): array
    {
        // Ntry is the booked account movement. Its amount and dates are the
        // reliable fallback when a bank does not provide TxDtls-level values.
        $entryAmountNode = $this->firstChild($entry, 'Amt');
        $entryIndicator = $this->textPath($entry, ['CdtDbtInd']);
        $entryAmount = $this->parseSignedAmount($entryAmountNode, $entryIndicator);

        $bookDate = $this->dateChoice($this->firstChild($entry, 'BookgDt'));
        $valueDate = $this->dateChoice($this->firstChild($entry, 'ValDt')) ?? $bookDate;
        if (null === $bookDate) {
            $bookDate = $valueDate;
        }
        if (null === $bookDate || null === $valueDate) {
            throw new \RuntimeException($this->trans('accounting.bank_import.parser.error.camt_missing_date'));
        }

        $details = [];
        foreach ($this->children($entry, 'NtryDtls') as $entryDetails) {
            foreach ($this->children($entryDetails, 'TxDtls') as $transactionDetails) {
                $details[] = $transactionDetails;
            }
        }

        // Some camt files expose only Ntry, others split one Ntry into one or
        // more TxDtls records. For journal lines, TxDtls is more descriptive.
        if ([] === $details) {
            return [$this->buildLine($entry, $entry, $entryAmount, $bookDate, $valueDate)];
        }

        $lines = [];
        foreach ($details as $transactionDetails) {
            $txAmountNode = $this->firstChild($transactionDetails, 'Amt');
            $txIndicator = $this->textPath($transactionDetails, ['CdtDbtInd']) ?: $entryIndicator;
            $amount = null !== $txAmountNode ? $this->parseSignedAmount($txAmountNode, $txIndicator) : $entryAmount;
            $lines[] = $this->buildLine($entry, $transactionDetails, $amount, $bookDate, $valueDate);
        }

        return $lines;
    }

    private function buildLine(
        \SimpleXMLElement $entry,
        \SimpleXMLElement $source,
        string $amount,
        \DateTimeImmutable $bookDate,
        \DateTimeImmutable $valueDate,
    ): StatementLineDto {
        $isIncoming = (float) $amount >= 0.0;
        // For incoming money the counterparty is usually Dbtr; for outgoing
        // money it is usually Cdtr. Fallbacks handle imperfect bank exports.
        $counterpartyName = $this->counterpartyName($source, $isIncoming);
        $counterpartyIban = $this->counterpartyIban($source, $isIncoming);

        return new StatementLineDto(
            bookDate: $bookDate,
            valueDate: $valueDate,
            amount: $amount,
            counterpartyName: $counterpartyName,
            counterpartyIban: $counterpartyIban,
            purpose: $this->purpose($entry, $source),
            endToEndId: $this->optionalTextPath($source, ['Refs', 'EndToEndId'])
                ?? $this->optionalTextPath($source, ['Refs', 'TxId']),
            mandateReference: $this->optionalTextPath($source, ['Refs', 'MndtId'])
                ?? $this->optionalTextPath($source, ['RltdPties', 'MndtRltdInf', 'MndtId']),
            creditorId: $this->firstText($this->xpath($source, './/*[local-name()="CdtrSchmeId"]//*[local-name()="Id"]'))
                ?? $this->optionalTextPath($source, ['RltdPties', 'Cdtr', 'Pty', 'Id', 'PrvtId', 'Othr', 'Id']),
        );
    }

    private function parseSignedAmount(?\SimpleXMLElement $amountNode, string $indicator): string
    {
        if (null === $amountNode) {
            throw new \RuntimeException($this->trans('accounting.bank_import.parser.error.camt_missing_amount'));
        }

        $indicator = strtoupper(trim($indicator));
        if (!in_array($indicator, ['CRDT', 'DBIT'], true)) {
            throw new \RuntimeException($this->trans('accounting.bank_import.parser.error.camt_missing_direction'));
        }

        $amount = (float) trim((string) $amountNode);
        // ISO amounts are unsigned; CdtDbtInd carries the direction.
        if ('DBIT' === $indicator) {
            $amount *= -1;
        }

        return number_format($amount, 2, '.', '');
    }

    private function entryStatus(\SimpleXMLElement $entry): string
    {
        // camt v8+ wraps the status code in <Cd> (or <Prtry/Cd> for proprietary
        // codes). The plain-text Sts shape only exists in pre-v8 documents and
        // is rejected by versionWarnings() before we get here.
        $code = $this->textPath($entry, ['Sts', 'Cd']);
        if ('' === $code) {
            $code = $this->textPath($entry, ['Sts', 'Prtry', 'Cd']);
        }

        return strtoupper(trim($code));
    }

    private function counterpartyName(\SimpleXMLElement $source, bool $isIncoming): string
    {
        $preferred = $isIncoming ? 'Dbtr' : 'Cdtr';
        $fallback = $isIncoming ? 'Cdtr' : 'Dbtr';

        $name = $this->optionalTextPath($source, ['RltdPties', $preferred, 'Pty', 'Nm'])
            ?? $this->optionalTextPath($source, ['RltdPties', 'Ultmt'.$preferred, 'Pty', 'Nm'])
            ?? $this->optionalTextPath($source, ['RltdPties', $fallback, 'Pty', 'Nm'])
            ?? '';

        // Sparkasse and others pad fixed-width name fields with runs of spaces;
        // collapse them so rule matching on the name is whitespace-insensitive.
        return preg_replace('/\s+/', ' ', $name) ?? $name;
    }

    private function counterpartyIban(\SimpleXMLElement $source, bool $isIncoming): ?string
    {
        $preferred = $isIncoming ? 'DbtrAcct' : 'CdtrAcct';
        $fallback = $isIncoming ? 'CdtrAcct' : 'DbtrAcct';

        return $this->normalizeIban($this->textPath($source, ['RltdPties', $preferred, 'Id', 'IBAN']))
            ?? $this->normalizeIban($this->textPath($source, ['RltdPties', $fallback, 'Id', 'IBAN']));
    }

    private function purpose(\SimpleXMLElement $entry, \SimpleXMLElement $source): string
    {
        $parts = [];
        // Ustrd is the normal German "Verwendungszweck"; structured remittance
        // references and AddtlNtryInf add useful context for rules/matching.
        foreach ($this->xpath($source, './*[local-name()="RmtInf"]/*[local-name()="Ustrd"]') as $node) {
            $this->appendPart($parts, (string) $node);
        }
        foreach ($this->xpath($source, './*[local-name()="RmtInf"]//*[local-name()="Ref"]') as $node) {
            $this->appendPart($parts, (string) $node);
        }
        foreach ($this->xpath($source, './*[local-name()="RmtInf"]//*[local-name()="AddtlRmtInf"]') as $node) {
            $this->appendPart($parts, (string) $node);
        }

        $this->appendPart($parts, $this->textPath($entry, ['AddtlNtryInf']));

        return implode(' ', $parts);
    }

    /**
     * @param list<string> $parts
     */
    private function appendPart(array &$parts, string $value): void
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        if ('' !== $value && !in_array($value, $parts, true)) {
            $parts[] = $value;
        }
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function extractPeriod(\SimpleXMLElement $container): array
    {
        $from = $this->firstDateText($this->xpath($container, './*[local-name()="FrToDt"]//*[local-name()="FrDt" or local-name()="FrDtTm"]'));
        $to = $this->firstDateText($this->xpath($container, './*[local-name()="FrToDt"]//*[local-name()="ToDt" or local-name()="ToDtTm"]'));

        return [$from, $to];
    }

    private function dateChoice(?\SimpleXMLElement $node): ?\DateTimeImmutable
    {
        if (null === $node) {
            return null;
        }

        return $this->firstDateText($this->xpath($node, './*[local-name()="Dt" or local-name()="DtTm"]'));
    }

    /**
     * @param list<\SimpleXMLElement> $nodes
     */
    private function firstDateText(array $nodes): ?\DateTimeImmutable
    {
        $value = $this->firstText($nodes);
        if (null === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function firstChild(\SimpleXMLElement $node, string $name): ?\SimpleXMLElement
    {
        return $this->children($node, $name)[0] ?? null;
    }

    /**
     * @return list<\SimpleXMLElement>
     */
    private function children(\SimpleXMLElement $node, string $name): array
    {
        return $this->xpath($node, './*[local-name()="'.$name.'"]');
    }

    /**
     * @return list<\SimpleXMLElement>
     */
    private function xpath(\SimpleXMLElement $node, string $query): array
    {
        $result = $node->xpath($query);
        if (false === $result) {
            return [];
        }

        return array_values(array_filter($result, static fn (mixed $item): bool => $item instanceof \SimpleXMLElement));
    }

    /**
     * @param list<string> $path
     */
    private function textPath(\SimpleXMLElement $node, array $path): string
    {
        $current = $node;
        foreach ($path as $segment) {
            $current = $this->firstChild($current, $segment);
            if (null === $current) {
                return '';
            }
        }

        return trim((string) $current);
    }

    /**
     * @param list<string> $path
     */
    private function optionalTextPath(\SimpleXMLElement $node, array $path): ?string
    {
        $value = $this->textPath($node, $path);

        return '' === $value ? null : $value;
    }

    /**
     * @param list<\SimpleXMLElement> $nodes
     */
    private function firstText(array $nodes): ?string
    {
        foreach ($nodes as $node) {
            $value = trim((string) $node);
            if ('' !== $value) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeIban(string $raw): ?string
    {
        $raw = strtoupper(preg_replace('/\s+/', '', trim($raw)) ?? '');

        return '' === $raw ? null : $raw;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator?->trans($key, $parameters) ?? $key;
    }
}
