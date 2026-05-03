<?php

declare(strict_types=1);

namespace App\Tests\Unit\BookingJournal\BankImport;

use App\Dto\BookingJournal\BankImport\ImportState;
use App\Entity\AccountingAccount;
use App\Entity\BankImportRule;
use App\Repository\BankImportRuleRepository;
use App\Service\BookingJournal\BankImport\BankImportRuleMatcher;
use App\Service\BookingJournal\BankImport\RuleActionApplicator;
use App\Service\BookingJournal\BankImport\RuleConditionEvaluator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class BankImportRuleMatcherTest extends TestCase
{
    public function testFirstMatchingRuleWinsByPriority(): void
    {
        // Two PayPal rules — the higher-priority one should fire even though
        // both could match.
        $highPriorityRule = $this->createRule(1, 100, [
            ['field' => BankImportRule::CONDITION_FIELD_COUNTERPARTY_NAME,
             'operator' => BankImportRule::CONDITION_OP_CONTAINS,
             'value' => 'PayPal'],
        ], ['mode' => BankImportRule::ACTION_MODE_ASSIGN, 'debitAccountId' => 999]);

        $lowPriorityRule = $this->createRule(2, 10, [
            ['field' => BankImportRule::CONDITION_FIELD_COUNTERPARTY_NAME,
             'operator' => BankImportRule::CONDITION_OP_CONTAINS,
             'value' => 'PayPal'],
        ], ['mode' => BankImportRule::ACTION_MODE_ASSIGN, 'debitAccountId' => 111]);

        $matcher = $this->createMatcher([$highPriorityRule, $lowPriorityRule]);
        $state = $this->createStateWithLines([
            ['counterpartyName' => 'PayPal Europe', 'amount' => '-19.99'],
        ]);

        $matcher->annotate($state, $this->createBankAccount());

        self::assertSame(1, $state->lines[0]['appliedRuleId']);
        self::assertSame(999, $state->lines[0]['userDebitAccountId']);
    }

    public function testAllConditionsMustMatchAndLogic(): void
    {
        $rule = $this->createRule(1, 50, [
            ['field' => BankImportRule::CONDITION_FIELD_COUNTERPARTY_NAME,
             'operator' => BankImportRule::CONDITION_OP_CONTAINS,
             'value' => 'PayPal'],
            ['field' => BankImportRule::CONDITION_FIELD_AMOUNT,
             'operator' => BankImportRule::CONDITION_OP_LT,
             'value' => -100],
        ], ['mode' => BankImportRule::ACTION_MODE_ASSIGN, 'debitAccountId' => 500]);

        $matcher = $this->createMatcher([$rule]);
        $state = $this->createStateWithLines([
            // Matches counterparty AND amount.
            ['counterpartyName' => 'PayPal Europe', 'amount' => '-150.00'],
            // Matches counterparty but NOT amount.
            ['counterpartyName' => 'PayPal Europe', 'amount' => '-50.00'],
        ]);

        $matcher->annotate($state, $this->createBankAccount());

        self::assertSame(1, $state->lines[0]['appliedRuleId']);
        self::assertNull($state->lines[1]['appliedRuleId']);
    }

    public function testDuplicatesAreSkipped(): void
    {
        $rule = $this->createRule(1, 50, [
            ['field' => BankImportRule::CONDITION_FIELD_COUNTERPARTY_NAME,
             'operator' => BankImportRule::CONDITION_OP_CONTAINS,
             'value' => 'PayPal'],
        ], ['mode' => BankImportRule::ACTION_MODE_ASSIGN, 'debitAccountId' => 500]);

        $matcher = $this->createMatcher([$rule]);
        $state = $this->createStateWithLines([
            ['counterpartyName' => 'PayPal', 'amount' => '-19.99', 'isDuplicate' => true,
             'status' => ImportState::LINE_STATUS_DUPLICATE],
        ]);

        $matcher->annotate($state, $this->createBankAccount());

        self::assertNull($state->lines[0]['appliedRuleId']);
    }

    public function testEmptyRulesNoEffect(): void
    {
        $matcher = $this->createMatcher([]);
        $state = $this->createStateWithLines([
            ['counterpartyName' => 'PayPal', 'amount' => '-19.99'],
        ]);

        $matcher->annotate($state, $this->createBankAccount());

        self::assertNull($state->lines[0]['appliedRuleId']);
        self::assertSame(ImportState::LINE_STATUS_PENDING, $state->lines[0]['status']);
    }

    public function testIgnoreActionMarksLine(): void
    {
        $rule = $this->createRule(1, 50, [
            ['field' => BankImportRule::CONDITION_FIELD_COUNTERPARTY_NAME,
             'operator' => BankImportRule::CONDITION_OP_CONTAINS,
             'value' => 'Sparen'],
        ], ['mode' => BankImportRule::ACTION_MODE_IGNORE]);

        $matcher = $this->createMatcher([$rule]);
        $state = $this->createStateWithLines([
            ['counterpartyName' => 'Sparen Alex', 'amount' => '-100.00'],
        ]);

        $matcher->annotate($state, $this->createBankAccount());

        self::assertTrue($state->lines[0]['isIgnored']);
        self::assertSame(ImportState::LINE_STATUS_IGNORED, $state->lines[0]['status']);
    }

    public function testRuleWarningsAreTranslatedWithLineNumber(): void
    {
        $rule = $this->createRule(1, 50, [
            ['field' => BankImportRule::CONDITION_FIELD_PURPOSE,
             'operator' => BankImportRule::CONDITION_OP_CONTAINS,
             'value' => 'Entgelte'],
        ], [
            'mode' => BankImportRule::ACTION_MODE_SPLIT,
            'splits' => [
                ['amountSource' => 'purpose_marker', 'marker' => 'Zinsen'],
            ],
        ]);

        $matcher = $this->createMatcher([$rule]);
        $state = $this->createStateWithLines([
            ['purpose' => 'Entgelte 41,50-', 'amount' => '-41.50'],
        ]);

        $matcher->annotate($state, $this->createBankAccount());

        self::assertSame([
            'Zeile 1: Splitbetrag fuer Marker "Zinsen" wurde im Verwendungszweck nicht gefunden.',
        ], $state->warnings);
    }

    /**
     * @param list<BankImportRule> $rules
     */
    private function createMatcher(array $rules): BankImportRuleMatcher
    {
        $repo = $this->createMock(BankImportRuleRepository::class);
        $repo->method('findActiveForAccount')->willReturn($rules);

        return new BankImportRuleMatcher(
            $repo,
            new RuleConditionEvaluator(),
            new RuleActionApplicator(),
            $this->translator(),
        );
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $parameters = []): string {
            $catalogue = [
                'accounting.bank_import.rule.warning.line' => 'Zeile %line%: %message%',
                'accounting.bank_import.rule.warning.split_marker_missing' => 'Splitbetrag fuer Marker "%marker%" wurde im Verwendungszweck nicht gefunden.',
            ];
            $message = $catalogue[$id] ?? $id;

            return strtr($message, array_map('strval', $parameters));
        });

        return $translator;
    }

    /**
     * @param list<array{field: string, operator: string, value: mixed}> $conditions
     * @param array<string, mixed>                                       $action
     */
    private function createRule(int $id, int $priority, array $conditions, array $action): BankImportRule
    {
        $rule = new BankImportRule();
        $rule->setName('rule '.$id);
        $rule->setPriority($priority);
        $rule->setConditions($conditions);
        $rule->setAction($action);

        (new \ReflectionClass($rule))->getProperty('id')->setValue($rule, $id);

        return $rule;
    }

    /**
     * @param list<array<string, mixed>> $partialLines
     */
    private function createStateWithLines(array $partialLines): ImportState
    {
        $state = new ImportState(
            sessionImportId: 'test',
            bankAccountId: 1,
            fileFormat: 'csv_generic',
            bankCsvProfileId: 1,
            originalFilename: 'test.csv',
            sourceIban: null,
            periodFrom: null,
            periodTo: null,
            createdAt: new \DateTimeImmutable(),
        );

        foreach ($partialLines as $idx => $partial) {
            $state->lines[] = array_merge([
                'idx' => $idx,
                'bookDate' => '2026-03-15',
                'valueDate' => '2026-03-15',
                'amount' => '0.00',
                'counterpartyName' => '',
                'counterpartyIban' => null,
                'purpose' => '',
                'endToEndId' => null,
                'mandateReference' => null,
                'creditorId' => null,
                'fingerprint' => 'fp'.$idx,
                'status' => ImportState::LINE_STATUS_PENDING,
                'isIgnored' => false,
                'isDuplicate' => false,
                'userDebitAccountId' => null,
                'userCreditAccountId' => null,
                'userTaxRateId' => null,
                'userRemark' => null,
                'appliedRuleId' => null,
                'matchedInvoiceId' => null,
                'matchedInvoiceNumber' => null,
                'splits' => [],
            ], $partial);
        }

        return $state;
    }

    private function createBankAccount(): AccountingAccount
    {
        $account = new AccountingAccount();
        $account->setAccountNumber('1200');
        $account->setName('Bank');
        $account->setIsBankAccount(true);

        return $account;
    }
}
