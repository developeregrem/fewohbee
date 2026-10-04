<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\AccountingAccount;
use App\Entity\ApiToken;
use App\Entity\Enum\ApiScope;
use App\Entity\TaxRate;
use App\Mcp\Security\McpDataFilter;
use App\Mcp\Security\McpRequiresScope;
use App\Mcp\Security\McpToolAuditor;
use App\Mcp\Security\McpToolException;
use App\Mcp\Support\McpInput;
use App\Repository\AccountingAccountRepository;
use App\Repository\TaxRateRepository;
use App\Security\ApiTokenContext;
use App\Service\BookingJournal\AccountingSettingsService;
use App\Service\BookingJournal\Receipt\ReceiptProposalService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Handing in receipts for booking. The assistant reads the receipt (PDF or photo in the client)
 * and submits what it read; FewohBee keeps it as a proposal until a person checks and books it in
 * the booking journal.
 *
 * On purpose the assistant learns nothing about the bookkeeping beyond the chart of accounts and
 * the VAT rates it needs to suggest a booking: no entries, balances or bank statements. Finding
 * the payment of a receipt happens in FewohBee.
 */
final class ReceiptTools
{
    private const LINES_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'amount' => ['type' => 'number', 'description' => 'Gross amount of this part including VAT, positive, at most two decimals.'],
            'taxRate' => ['type' => ['number', 'null'], 'description' => 'VAT rate in percent, e.g. 19, 7 or 0 (tax-free). See list_tax_rates.'],
            'accountNumber' => ['type' => ['string', 'null'], 'description' => 'Suggested account for what was bought, e.g. "4930" (see list_accounting_accounts). Null when unsure; the person picks it then.'],
            'text' => ['type' => ['string', 'null'], 'description' => 'Short summary of the positions in this part, e.g. "Putz, Farbwalzen".', 'maxLength' => 200],
        ],
        'required' => ['amount', 'taxRate'],
        'additionalProperties' => false,
    ];

    public function __construct(
        private readonly AccountingAccountRepository $accountRepository,
        private readonly TaxRateRepository $taxRateRepository,
        private readonly AccountingSettingsService $settingsService,
        private readonly ReceiptProposalService $proposalService,
        private readonly ApiTokenContext $apiTokenContext,
        private readonly McpToolAuditor $auditor,
        #[Autowire(service: 'limiter.mcp_write')]
        private readonly RateLimiterFactoryInterface $writeLimiter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_accounting_accounts',
        title: 'List accounting accounts',
        description: 'Expense and revenue accounts of the chart of accounts in use (e.g. SKR03), to suggest the account of a receipt part. Numbers and names only.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::RECEIPTS_SUBMIT)]
    public function listAccounts(
        #[Schema(type: 'string', description: 'Only accounts whose number or name contains this text.', maxLength: 50)]
        ?string $search = null,
    ): array {
        $needle = null !== $search ? mb_strtolower(trim($search)) : '';
        $accounts = [];
        foreach ($this->accountRepository->findAllOrdered($this->settingsService->getActivePreset()) as $account) {
            if (!\in_array($account->getType(), [AccountingAccount::TYPE_EXPENSE, AccountingAccount::TYPE_REVENUE], true)) {
                continue;
            }
            if ('' !== $needle && !str_contains(mb_strtolower($account->getAccountNumber().' '.$account->getName()), $needle)) {
                continue;
            }
            $accounts[] = ['number' => $account->getAccountNumber(), 'name' => McpDataFilter::wrapUntrusted($account->getName(), 150), 'type' => $account->getType()];
        }

        return ['chartOfAccounts' => $this->settingsService->getActivePreset(), 'accounts' => $accounts];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_tax_rates',
        title: 'List VAT rates',
        description: 'VAT rates set up for bookings, optionally only those valid on a date.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::RECEIPTS_SUBMIT)]
    public function listTaxRates(
        #[Schema(type: 'string', description: 'Only rates valid on this date, YYYY-MM-DD.')]
        ?string $date = null,
    ): array {
        $preset = $this->settingsService->getActivePreset();
        $rates = null !== $date
            ? $this->taxRateRepository->findValidAt(McpInput::date($date, 'date'), $preset)
            : $this->taxRateRepository->findAllOrdered($preset);

        return ['taxRates' => array_map(static fn (TaxRate $rate): array => [
            'name' => McpDataFilter::wrapUntrusted($rate->getName(), 150),
            'rate' => $rate->getRateFloat(),
            'validFrom' => $rate->getValidFrom()?->format('Y-m-d'),
            'validTo' => $rate->getValidTo()?->format('Y-m-d'),
        ], array_values($rates))];
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'submit_receipt',
        title: 'Hand in receipt',
        description: 'Hands in a receipt for booking. Nothing is booked: FewohBee keeps it as a proposal, finds the matching payment itself and a person books it in the booking journal. Give one line per account and VAT rate with gross amounts, discounts already deducted; the lines must add up to the total. A repeated numbered receipt returns its open proposal. Unnumbered receipts are always new, even if supplier, date and total match.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::RECEIPTS_SUBMIT)]
    public function submit(
        #[Schema(description: 'Supplier as printed on the receipt, e.g. "Bauhaus".', minLength: 1, maxLength: 150)]
        string $supplier,
        #[Schema(description: 'Receipt date, YYYY-MM-DD.')]
        string $date,
        // A plain minimum: the SDK writes exclusiveMinimum as boolean (draft-04), which current
        // clients reject together with the whole tool.
        #[Schema(type: 'number', description: 'Gross total paid, e.g. 303.92.', minimum: 0.01)]
        int|float $total,
        #[Schema(description: 'One part per account and VAT rate.', items: self::LINES_SCHEMA, minItems: 1, maxItems: ReceiptProposalService::MAX_LINES)]
        array $lines,
        #[Schema(type: 'string', description: 'Receipt or invoice number.', maxLength: 50)]
        ?string $receiptNumber = null,
        #[Schema(type: 'string', description: 'How it was paid according to the receipt: "bank" (card, transfer, direct debit), "cash" or "unknown".', enum: ['bank', 'cash', 'unknown'])]
        ?string $payment = null,
        #[Schema(type: 'string', description: 'Anything the person should check, e.g. an unreadable position.', maxLength: 1000)]
        ?string $note = null,
    ): array {
        $parsed = [];
        foreach (array_values($lines) as $index => $line) {
            if (!\is_array($line) || !isset($line['amount']) || !\array_key_exists('taxRate', $line)) {
                throw McpToolException::invalid(\sprintf('lines[%d] needs amount and taxRate.', $index));
            }
            $taxRate = $line['taxRate'];
            if (null !== $taxRate && !\is_int($taxRate) && !\is_float($taxRate)) {
                throw McpToolException::invalid(\sprintf('lines[%d].taxRate must be a number or null.', $index));
            }
            $parsed[] = [
                'text' => self::text($line['text'] ?? null, 200),
                'amount' => self::amount($line['amount'], \sprintf('lines[%d].amount', $index)),
                'taxRate' => null !== $taxRate ? (string) $taxRate : null,
                'accountNumber' => self::text($line['accountNumber'] ?? null, 10),
            ];
        }

        $apiToken = $this->apiTokenContext->getToken();
        if (!$apiToken instanceof ApiToken) {
            // Unreachable behind the mcp firewall; fail closed regardless.
            throw McpToolException::disabled('No access token.');
        }
        if (!$this->writeLimiter->create('token-'.$apiToken->getId())->consume()->isAccepted()) {
            throw McpToolException::rateLimited('Too many receipts were handed in with this access token within the last hour. Try again later.');
        }

        [$proposal, $existed] = McpInput::guard(fn (): array => $this->proposalService->submit(
            $supplier,
            McpInput::date($date, 'date'),
            self::text($receiptNumber, 50),
            self::amount($total, 'total'),
            $payment ?? 'unknown',
            $parsed,
            self::text($note, 1000),
            $apiToken->getUser(),
            $apiToken->getTokenPrefix(),
        ));

        $this->auditor->note('proposalId', (int) $proposal->getId());
        $this->auditor->note('alreadySubmitted', $existed);

        return [
            'proposalId' => $proposal->getId(),
            'alreadySubmitted' => $existed,
            'status' => $proposal->getStatus()->value,
            'nextStep' => 'Tell the user that the receipt waits for approval under Booking journal > Receipt proposals in FewohBee. Nothing has been booked yet.',
        ];
    }

    /** A JSON number as decimal string with two places; more decimals are refused, not rounded. */
    private static function amount(mixed $value, string $field): string
    {
        if (!\is_int($value) && !\is_float($value)) {
            throw McpToolException::invalid(\sprintf("'%s' must be a number.", $field));
        }
        if (!is_finite((float) $value) || $value <= 0 || $value > 99999999999.99) {
            throw McpToolException::invalid(\sprintf("'%s' must be positive and fit the receipt amount limit.", $field));
        }
        $cents = round($value * 100);
        if (abs($value * 100 - $cents) > 1e-6) {
            throw McpToolException::invalid(\sprintf("'%s' must be positive with at most two decimals.", $field));
        }

        return number_format($cents / 100, 2, '.', '');
    }

    private static function text(mixed $value, int $maxLength): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : mb_substr($value, 0, $maxLength);
    }
}
