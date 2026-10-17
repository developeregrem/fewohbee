<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Dto\BookingJournal\BankImport\ImportState;
use App\Entity\AccountingAccount;
use App\Entity\BankImportDraft;
use App\Entity\BankImportRule;
use App\Entity\Enum\ApiScope;
use App\Entity\TaxRate;
use App\Mcp\Security\McpDataFilter;
use App\Mcp\Security\McpRequiresScope;
use App\Mcp\Security\McpToolAuditor;
use App\Mcp\Security\McpToolException;
use App\Repository\AccountingAccountRepository;
use App\Repository\BankImportRuleRepository;
use App\Repository\TaxRateRepository;
use App\Service\BookingJournal\BankImport\BankImportDraftStore;
use App\Service\BookingJournal\BankImport\BankImportLineEditor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * Lets an assistant prepare the bank statement imports its owner uploaded: read the open lines,
 * the chart of accounts and the import rules, and fill in accounts, tax rates and remarks.
 * Uploading and committing stay in FewohBee, so nothing reaches the journal without the user.
 *
 * bank-import:write covers sharing counterparty names and purposes (as untrusted_text); IBANs are
 * shortened to country code and last four digits.
 */
final class BankImportTools
{
    private const MAX_LINES = 100;
    private const LINE_STATUSES = ['pending', 'ready', 'ignored', 'duplicate', 'all'];

    public function __construct(
        private readonly BankImportDraftStore $drafts,
        private readonly AccountingAccountRepository $accountRepository,
        private readonly TaxRateRepository $taxRateRepository,
        private readonly BankImportRuleRepository $ruleRepository,
        private readonly McpToolAuditor $auditor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_bank_import_drafts',
        title: 'List bank import drafts',
        description: 'Lists the bank statement imports the owner of this access token uploaded in FewohBee and has not committed yet, with their bank account, period and line counts per status. Drafts are deleted after 2 days without changes.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::BANK_IMPORT_WRITE)]
    public function listDrafts(): array
    {
        return [
            'drafts' => array_map(function (BankImportDraft $draft): array {
                $state = ImportState::fromArray($draft->getState());

                return [
                    'draftId' => $draft->getId(),
                    'bankAccount' => $this->describeAccount($this->accountRepository->find($state->bankAccountId)),
                    'periodFrom' => $state->periodFrom,
                    'periodTo' => $state->periodTo,
                    'uploadedAt' => $draft->getCreatedAt()->format(\DateTimeInterface::ATOM),
                    'expiresAt' => $draft->getExpiresAt()->format(\DateTimeInterface::ATOM),
                    'lines' => $state->countByStatus(),
                ];
            }, $this->drafts->list()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_bank_import_lines',
        title: 'Get bank import lines',
        description: 'Returns the lines of a bank import draft (at most 100 per call) with date, amount (negative = outgoing), counterparty, purpose, the accounts, tax rate and remark assigned so far, a matched invoice and the applied import rule. A line is "ready" once debit and credit account are set (or an invoice was matched); "duplicate" lines were imported before and cannot be changed.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::BANK_IMPORT_WRITE)]
    public function getLines(
        #[Schema(description: 'Draft id from list_bank_import_drafts.')]
        string $draftId,
        #[Schema(description: 'Only lines with this status.', enum: ['pending', 'ready', 'ignored', 'duplicate', 'all'])]
        string $status = 'pending',
        #[Schema(description: 'Number of matching lines to skip (pagination).', minimum: 0)]
        int $offset = 0,
    ): array {
        if (!\in_array($status, self::LINE_STATUSES, true)) {
            throw McpToolException::invalid(\sprintf("'status' must be one of: %s.", implode(', ', self::LINE_STATUSES)));
        }
        $state = $this->loadState($draftId);

        $matching = array_values(array_filter(
            $state->lines,
            static fn (array $line): bool => 'all' === $status || $status === ($line['status'] ?? ImportState::LINE_STATUS_PENDING),
        ));
        $offset = max(0, $offset);
        $page = \array_slice($matching, $offset, self::MAX_LINES);

        return [
            'draftId' => $state->sessionImportId,
            'bankAccountId' => $state->bankAccountId,
            'counts' => $state->countByStatus(),
            'lines' => array_map($this->describeLine(...), $page),
            'total' => \count($matching),
            'offset' => $offset,
            'hasMore' => $offset + \count($page) < \count($matching),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_bank_import_context',
        title: 'Get bank import context',
        description: 'What is needed to assign accounts to the lines of a bank import draft: the bank account of the draft, the chart of accounts, the tax rates valid today and the active import rules (conditions and the accounts they assign). Recurring transactions should follow the rules and how similar lines were assigned.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::BANK_IMPORT_WRITE)]
    public function getContext(
        #[Schema(description: 'Draft id from list_bank_import_drafts.')]
        string $draftId,
    ): array {
        $state = $this->loadState($draftId);
        $bankAccount = $this->bankAccount($state);
        $preset = $bankAccount->getChartPreset();

        return [
            'bankAccount' => $this->describeAccount($bankAccount),
            'accounts' => array_map(fn (AccountingAccount $account): array => $this->describeAccount($account) + [
                'type' => $account->getType(),
            ], $this->accountRepository->findAllOrdered($preset)),
            'taxRates' => array_map(static fn (TaxRate $rate): array => [
                'id' => $rate->getId(),
                'name' => $rate->getName(),
                'rate' => $rate->getRateFloat(),
            ], $this->taxRateRepository->findValidAt(new \DateTimeImmutable(), $preset)),
            'rules' => array_map(static fn (BankImportRule $rule): array => [
                'id' => $rule->getId(),
                'name' => $rule->getName(),
                'priority' => $rule->getPriority(),
                'conditions' => array_map(static fn (array $condition): array => BankImportRule::CONDITION_FIELD_COUNTERPARTY_IBAN === ($condition['field'] ?? null)
                    ? ['value' => self::maskIban(\is_string($condition['value'] ?? null) ? $condition['value'] : null)] + $condition
                    : $condition, $rule->getConditions()),
                'action' => $rule->getAction(),
            ], $this->ruleRepository->findActiveForAccount($bankAccount)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $changes
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'update_bank_import_lines',
        title: 'Update bank import lines',
        description: 'Fills in lines of a bank import draft (at most 100 per call): debit and credit account, tax rate, remark, invoice number, or marks a line as ignored. Only the given fields change; pass null to clear one. Nothing is posted to the journal: the user reviews the draft and commits it in FewohBee. Tell the user which lines you changed and why.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::BANK_IMPORT_WRITE)]
    public function updateLines(
        #[Schema(description: 'Draft id from list_bank_import_drafts.')]
        string $draftId,
        #[Schema(
            description: 'One entry per line: {"idx": 3, "debitAccountId": 12, "creditAccountId": 4, "taxRateId": 2, "remark": "...", "invoiceNumber": "...", "ignore": true}.',
            items: [
                'type' => 'object',
                'properties' => [
                    'idx' => ['type' => 'integer', 'minimum' => 0],
                    'debitAccountId' => ['type' => ['integer', 'null']],
                    'creditAccountId' => ['type' => ['integer', 'null']],
                    'taxRateId' => ['type' => ['integer', 'null']],
                    'remark' => ['type' => ['string', 'null'], 'maxLength' => 255],
                    'invoiceNumber' => ['type' => ['string', 'null'], 'maxLength' => 50],
                    'ignore' => ['type' => 'boolean'],
                ],
                'required' => ['idx'],
            ],
            minItems: 1,
            maxItems: 100,
        )]
        array $changes,
    ): array {
        if ([] === $changes || \count($changes) > self::MAX_LINES) {
            throw McpToolException::invalid(\sprintf("'changes' needs 1 to %d entries.", self::MAX_LINES));
        }
        $state = $this->loadState($draftId);
        $bankAccount = $this->bankAccount($state);
        $accountIds = array_map(static fn (AccountingAccount $account): ?int => $account->getId(), $this->accountRepository->findAllOrdered($bankAccount->getChartPreset()));
        $taxRateIds = array_map(static fn (TaxRate $rate): ?int => $rate->getId(), $this->taxRateRepository->findValidAt(new \DateTimeImmutable(), $bankAccount->getChartPreset()));

        // Validate everything first: a refused entry must not leave the draft half changed.
        $fieldsByIdx = [];
        foreach ($changes as $change) {
            $idx = \is_array($change) && \is_int($change['idx'] ?? null) ? $change['idx'] : -1;
            if (!isset($state->lines[$idx])) {
                throw McpToolException::invalid(\sprintf('Unknown line idx %s.', var_export($change['idx'] ?? null, true)));
            }
            if (!BankImportLineEditor::isEditable($state->lines[$idx])) {
                throw McpToolException::invalid(\sprintf('Line %d was imported before (duplicate) and cannot be changed.', $idx));
            }
            $fieldsByIdx[$idx] = $this->fields($change, $idx, $accountIds, $taxRateIds);
        }

        $state = $this->drafts->update($draftId, static function (ImportState $state) use ($fieldsByIdx): void {
            foreach ($fieldsByIdx as $idx => $fields) {
                foreach ($fields as $field => $value) {
                    BankImportLineEditor::setField($state->lines[$idx], $field, $value);
                }
            }
        }) ?? throw McpToolException::invalid('Unknown draft id, or the draft expired or was committed meanwhile.');

        $this->auditor->note('draftId', $draftId);
        $this->auditor->note('lines', array_keys($fieldsByIdx));

        return [
            'updated' => array_map(static fn (int $idx): array => ['idx' => $idx, 'status' => $state->lines[$idx]['status']], array_keys($fieldsByIdx)),
            'counts' => $state->countByStatus(),
            'nextStep' => 'Tell the user what you assigned. The user reviews the draft and commits it in FewohBee (Journal > Bank import).',
        ];
    }

    /**
     * The editor fields of one change entry, with ids checked against the draft's chart of accounts.
     *
     * @param array<string, mixed> $change
     * @param list<int|null>       $accountIds
     * @param list<int|null>       $taxRateIds
     *
     * @return array<string, mixed>
     */
    private function fields(array $change, int $idx, array $accountIds, array $taxRateIds): array
    {
        $fields = [];
        foreach (['debitAccountId' => $accountIds, 'creditAccountId' => $accountIds, 'taxRateId' => $taxRateIds] as $field => $validIds) {
            if (!\array_key_exists($field, $change)) {
                continue;
            }
            if (null !== $change[$field] && !\in_array($change[$field], $validIds, true)) {
                throw McpToolException::invalid(\sprintf("Line %d: '%s' %s is not in the chart of accounts of this bank account (see get_bank_import_context).", $idx, $field, var_export($change[$field], true)));
            }
            $fields[$field] = $change[$field];
        }
        foreach (['remark', 'invoiceNumber'] as $field) {
            if (\array_key_exists($field, $change)) {
                $fields[$field] = null !== $change[$field] ? (string) $change[$field] : null;
            }
        }
        if (\array_key_exists('ignore', $change)) {
            $fields['isIgnored'] = true === $change['ignore'] ? 1 : 0;
        }
        if ([] === $fields) {
            throw McpToolException::invalid(\sprintf('Line %d: nothing to change.', $idx));
        }

        return $fields;
    }

    private function loadState(string $draftId): ImportState
    {
        return $this->drafts->load($draftId)
            ?? throw McpToolException::invalid('Unknown draft id, or the draft expired or was committed. See list_bank_import_drafts.');
    }

    private function bankAccount(ImportState $state): AccountingAccount
    {
        return $this->accountRepository->find($state->bankAccountId)
            ?? throw McpToolException::invalid('The bank account of this draft no longer exists.');
    }

    /**
     * @param array<string, mixed> $line
     *
     * @return array<string, mixed>
     */
    private function describeLine(array $line): array
    {
        return [
            'idx' => $line['idx'],
            'bookDate' => $line['bookDate'],
            'valueDate' => $line['valueDate'],
            'amount' => $line['amount'],
            'counterpartyName' => McpDataFilter::wrapUntrusted($line['counterpartyName'] ?? null, 200),
            'counterpartyIban' => self::maskIban($line['counterpartyIban'] ?? null),
            'purpose' => McpDataFilter::wrapUntrusted($line['purpose'] ?? null),
            'status' => $line['status'] ?? ImportState::LINE_STATUS_PENDING,
            'debitAccountId' => $line['userDebitAccountId'] ?? null,
            'creditAccountId' => $line['userCreditAccountId'] ?? null,
            'taxRateId' => $line['userTaxRateId'] ?? null,
            'remark' => McpDataFilter::wrapUntrusted($line['userRemark'] ?? null),
            'invoiceNumber' => $line['userInvoiceNumber'] ?? null,
            'matchedInvoice' => null !== ($line['matchedInvoiceId'] ?? null) ? [
                'id' => $line['matchedInvoiceId'],
                'number' => $line['matchedInvoiceNumber'] ?? null,
                'amountMatches' => (bool) ($line['matchedInvoiceAmountMatches'] ?? false),
            ] : null,
            'appliedRuleId' => $line['appliedRuleId'] ?? null,
            'splitCount' => \count($line['splits'] ?? []),
        ];
    }

    /**
     * @return array{id: int|null, number: string, name: string, iban: string|null}|null
     */
    private function describeAccount(?AccountingAccount $account): ?array
    {
        return null === $account ? null : [
            'id' => $account->getId(),
            'number' => $account->getAccountNumber(),
            'name' => $account->getName(),
            'iban' => self::maskIban($account->getIban()),
        ];
    }

    /** Country code and last four digits, e.g. "DE…0461". */
    public static function maskIban(?string $iban): ?string
    {
        $iban = null !== $iban ? strtoupper((string) preg_replace('/\s+/', '', $iban)) : '';

        return \strlen($iban) >= 8 ? substr($iban, 0, 2).'…'.substr($iban, -4) : null;
    }
}
