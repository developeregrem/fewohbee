<?php

declare(strict_types=1);

namespace App\Dto\BookingJournal\BankImport;

/**
 * Session-resident state of an in-progress bank statement import.
 *
 * Lives only in the Symfony session and is serialized as a plain array via
 * {@see toArray()} / {@see fromArray()} so the session storage stays simple
 * and fast (native file sessions, redis, etc.).
 *
 * The raw statement file itself is parsed once at upload and never persisted —
 * everything the UI needs sits in {@see $lines}.
 */
final class ImportState
{
    public const LINE_STATUS_PENDING = 'pending';
    public const LINE_STATUS_READY = 'ready';
    public const LINE_STATUS_IGNORED = 'ignored';
    public const LINE_STATUS_DUPLICATE = 'duplicate';

    /**
     * Re-derives a single line's status from its current flags. Lines flagged
     * as duplicate or ignored short-circuit; otherwise a line is "ready" once
     * a user can post it (splits set, an auto-matched invoice on an incoming
     * line, or both account sides assigned).
     *
     * @param array<string, mixed> $line
     */
    public static function deriveLineStatus(array $line): string
    {
        if (true === ($line['isDuplicate'] ?? false)) {
            return self::LINE_STATUS_DUPLICATE;
        }

        if (true === ($line['isIgnored'] ?? false)) {
            return self::LINE_STATUS_IGNORED;
        }

        $hasSplits = !empty($line['splits']);
        $hasInvoiceAutoMatch = null !== ($line['matchedInvoiceId'] ?? null)
            && true === ($line['matchedInvoiceAmountMatches'] ?? false)
            && ((float) ($line['amount'] ?? 0)) >= 0.0
            && null !== ($line['userDebitAccountId'] ?? null);
        $hasAccounts = null !== ($line['userDebitAccountId'] ?? null)
                    && null !== ($line['userCreditAccountId'] ?? null);

        return ($hasSplits || $hasInvoiceAutoMatch || $hasAccounts) ? self::LINE_STATUS_READY : self::LINE_STATUS_PENDING;
    }

    /**
     * Walks all lines and re-derives their status. Called after the matchers
     * run on a freshly parsed import so the UI starts with consistent badges.
     */
    public function normalizeLineStatuses(): void
    {
        foreach ($this->lines as &$line) {
            // An invoice was matched but the amounts don't line up — the user
            // still has to confirm or correct, so we keep the line pending.
            if (null !== ($line['matchedInvoiceId'] ?? null)
                && true !== ($line['matchedInvoiceAmountMatches'] ?? false)
                && empty($line['splits'])
                && true !== ($line['isDuplicate'] ?? false)
                && true !== ($line['isIgnored'] ?? false)
            ) {
                $line['status'] = self::LINE_STATUS_PENDING;
                continue;
            }

            $line['status'] = self::deriveLineStatus($line);
        }
        unset($line);
    }

    /**
     * @return array{total: int, pending: int, ready: int, ignored: int, duplicate: int}
     */
    public function countByStatus(): array
    {
        $counts = ['total' => 0, 'pending' => 0, 'ready' => 0, 'ignored' => 0, 'duplicate' => 0];
        foreach ($this->lines as $line) {
            ++$counts['total'];
            $status = $line['status'] ?? self::LINE_STATUS_PENDING;
            if (isset($counts[$status])) {
                ++$counts[$status];
            }
        }

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    public function __construct(
        public string $sessionImportId,
        public int $bankAccountId,
        public string $fileFormat,
        public ?int $bankCsvProfileId,
        public string $originalFilename,
        public ?string $sourceIban,
        public ?string $periodFrom,
        public ?string $periodTo,
        public \DateTimeImmutable $createdAt,
        public array $lines = [],
        public array $warnings = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sessionImportId: (string) $data['sessionImportId'],
            bankAccountId: (int) $data['bankAccountId'],
            fileFormat: (string) $data['fileFormat'],
            bankCsvProfileId: isset($data['bankCsvProfileId']) ? (int) $data['bankCsvProfileId'] : null,
            originalFilename: (string) $data['originalFilename'],
            sourceIban: $data['sourceIban'] ?? null,
            periodFrom: $data['periodFrom'] ?? null,
            periodTo: $data['periodTo'] ?? null,
            createdAt: new \DateTimeImmutable((string) $data['createdAt']),
            lines: $data['lines'] ?? [],
            warnings: $data['warnings'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sessionImportId'   => $this->sessionImportId,
            'bankAccountId'     => $this->bankAccountId,
            'fileFormat'        => $this->fileFormat,
            'bankCsvProfileId'  => $this->bankCsvProfileId,
            'originalFilename'  => $this->originalFilename,
            'sourceIban'        => $this->sourceIban,
            'periodFrom'        => $this->periodFrom,
            'periodTo'          => $this->periodTo,
            'createdAt'         => $this->createdAt->format(\DateTimeInterface::ATOM),
            'lines'             => $this->lines,
            'warnings'          => $this->warnings,
        ];
    }

    /**
     * Builds a fresh state from a {@see ParseResult}.
     */
    public static function fromParseResult(
        string $sessionImportId,
        int $bankAccountId,
        string $fileFormat,
        ?int $bankCsvProfileId,
        string $originalFilename,
        ParseResult $result,
    ): self {
        $lines = [];
        foreach ($result->lines as $idx => $dto) {
            $lines[] = [
                'idx'              => $idx,
                'bookDate'         => $dto->bookDate->format('Y-m-d'),
                'valueDate'        => $dto->valueDate->format('Y-m-d'),
                'amount'           => $dto->amount,
                'counterpartyName' => $dto->counterpartyName,
                'counterpartyIban' => $dto->counterpartyIban,
                'purpose'          => $dto->purpose,
                'endToEndId'       => $dto->endToEndId,
                'mandateReference' => $dto->mandateReference,
                'creditorId'       => $dto->creditorId,
                'fingerprint'      => $dto->fingerprint(),
                'status'           => self::LINE_STATUS_PENDING,
                'isIgnored'        => false,
                'isDuplicate'      => false,
                'userDebitAccountId'  => null,
                'userCreditAccountId' => null,
                'userTaxRateId'    => null,
                'userRemark'       => null,
                'appliedRuleId'    => null,
                'matchedInvoiceId' => null,
                'matchedInvoiceNumber' => null,
                'matchedInvoiceAmountMatches' => false,
                'splits'           => [],
            ];
        }

        return new self(
            sessionImportId: $sessionImportId,
            bankAccountId: $bankAccountId,
            fileFormat: $fileFormat,
            bankCsvProfileId: $bankCsvProfileId,
            originalFilename: $originalFilename,
            sourceIban: $result->sourceIban,
            periodFrom: $result->periodFrom?->format('Y-m-d'),
            periodTo: $result->periodTo?->format('Y-m-d'),
            createdAt: new \DateTimeImmutable(),
            lines: $lines,
            warnings: $result->warnings,
        );
    }
}
