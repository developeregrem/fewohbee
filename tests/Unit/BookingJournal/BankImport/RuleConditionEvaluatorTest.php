<?php

declare(strict_types=1);

namespace App\Tests\Unit\BookingJournal\BankImport;

use App\Entity\BankImportRule;
use App\Service\BookingJournal\BankImport\RuleConditionEvaluator;
use PHPUnit\Framework\TestCase;

final class RuleConditionEvaluatorTest extends TestCase
{
    private RuleConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new RuleConditionEvaluator();
    }

    public function testContainsIsCaseInsensitive(): void
    {
        $line = ['counterpartyName' => 'PayPal Europe', 'amount' => '-19.99'];

        self::assertTrue($this->evaluator->matches([
            'field' => BankImportRule::CONDITION_FIELD_COUNTERPARTY_NAME,
            'operator' => BankImportRule::CONDITION_OP_CONTAINS,
            'value' => 'paypal',
        ], $line));

        self::assertFalse($this->evaluator->matches([
            'field' => BankImportRule::CONDITION_FIELD_COUNTERPARTY_NAME,
            'operator' => BankImportRule::CONDITION_OP_CONTAINS,
            'value' => 'klarna',
        ], $line));
    }

    public function testNotContainsInverts(): void
    {
        $line = ['purpose' => 'Reservierung Buchung'];

        self::assertTrue($this->evaluator->matches([
            'field' => BankImportRule::CONDITION_FIELD_PURPOSE,
            'operator' => BankImportRule::CONDITION_OP_NOT_CONTAINS,
            'value' => 'rückerstattung',
        ], $line));

        self::assertFalse($this->evaluator->matches([
            'field' => BankImportRule::CONDITION_FIELD_PURPOSE,
            'operator' => BankImportRule::CONDITION_OP_NOT_CONTAINS,
            'value' => 'buchung',
        ], $line));
    }

    public function testEqualsForIban(): void
    {
        $line = ['counterpartyIban' => 'DE89370400440532013000'];

        self::assertTrue($this->evaluator->matches([
            'field' => BankImportRule::CONDITION_FIELD_COUNTERPARTY_IBAN,
            'operator' => BankImportRule::CONDITION_OP_EQUALS,
            'value' => 'DE89370400440532013000',
        ], $line));

        self::assertFalse($this->evaluator->matches([
            'field' => BankImportRule::CONDITION_FIELD_COUNTERPARTY_IBAN,
            'operator' => BankImportRule::CONDITION_OP_EQUALS,
            'value' => 'DE12345678901234567890',
        ], $line));
    }

    public function testRegexMatchesCaseInsensitively(): void
    {
        $line = ['purpose' => 'Tibber Rechnung 4541269'];

        self::assertTrue($this->evaluator->matches([
            'field' => BankImportRule::CONDITION_FIELD_PURPOSE,
            'operator' => BankImportRule::CONDITION_OP_REGEX,
            'value' => '/tibber/',
        ], $line));

        // Bare patterns get default delimiters + /i flag.
        self::assertTrue($this->evaluator->matches([
            'field' => BankImportRule::CONDITION_FIELD_PURPOSE,
            'operator' => BankImportRule::CONDITION_OP_REGEX,
            'value' => 'rechnung\\s+\\d+',
        ], $line));
    }

    public function testRegexInvalidPatternIsSafelyFalse(): void
    {
        $line = ['purpose' => 'whatever'];

        self::assertFalse($this->evaluator->matches([
            'field' => BankImportRule::CONDITION_FIELD_PURPOSE,
            'operator' => BankImportRule::CONDITION_OP_REGEX,
            'value' => '/(/',
        ], $line));
    }

    public function testAmountGtLtBetween(): void
    {
        $line = ['amount' => '-150.00'];

        self::assertTrue($this->evaluator->matches([
            'field' => BankImportRule::CONDITION_FIELD_AMOUNT,
            'operator' => BankImportRule::CONDITION_OP_LT,
            'value' => 0,
        ], $line));

        self::assertTrue($this->evaluator->matches([
            'field' => BankImportRule::CONDITION_FIELD_AMOUNT,
            'operator' => BankImportRule::CONDITION_OP_BETWEEN,
            'value' => [-200, -100],
        ], $line));

        self::assertFalse($this->evaluator->matches([
            'field' => BankImportRule::CONDITION_FIELD_AMOUNT,
            'operator' => BankImportRule::CONDITION_OP_GT,
            'value' => 0,
        ], $line));
    }

    public function testDirectionField(): void
    {
        $outgoing = ['amount' => '-50.00'];
        $incoming = ['amount' => '50.00'];

        self::assertTrue($this->evaluator->matches([
            'field' => BankImportRule::CONDITION_FIELD_DIRECTION,
            'operator' => BankImportRule::CONDITION_OP_EQUALS,
            'value' => 'out',
        ], $outgoing));

        self::assertTrue($this->evaluator->matches([
            'field' => BankImportRule::CONDITION_FIELD_DIRECTION,
            'operator' => BankImportRule::CONDITION_OP_EQUALS,
            'value' => 'in',
        ], $incoming));
    }

    public function testEmptyContainsValueDoesNotMatch(): void
    {
        // Otherwise an unconfigured rule would match every line.
        self::assertFalse($this->evaluator->matches([
            'field' => BankImportRule::CONDITION_FIELD_PURPOSE,
            'operator' => BankImportRule::CONDITION_OP_CONTAINS,
            'value' => '',
        ], ['purpose' => 'irgendwas']));
    }
}
