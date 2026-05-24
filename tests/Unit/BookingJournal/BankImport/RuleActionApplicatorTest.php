<?php

declare(strict_types=1);

namespace App\Tests\Unit\BookingJournal\BankImport;

use App\Dto\BookingJournal\BankImport\ImportState;
use App\Entity\BankImportRule;
use App\Service\BookingJournal\BankImport\RuleActionApplicator;
use PHPUnit\Framework\TestCase;

final class RuleActionApplicatorTest extends TestCase
{
    private RuleActionApplicator $applicator;

    protected function setUp(): void
    {
        $this->applicator = new RuleActionApplicator();
    }

    public function testIgnoreActionFlagsLine(): void
    {
        $rule = $this->createRule(['mode' => BankImportRule::ACTION_MODE_IGNORE]);
        $line = $this->emptyLine();

        $this->applicator->apply($rule, $line);

        self::assertTrue($line['isIgnored']);
        self::assertSame(ImportState::LINE_STATUS_IGNORED, $line['status']);
        self::assertNotNull($line['appliedRuleId']);
    }

    public function testAssignActionSetsAccountsAndRendersTemplate(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_ASSIGN,
            'debitAccountId' => 42,
            'creditAccountId' => 17,
            'taxRateId' => 19,
            'remarkTemplate' => 'Zahlung {counterparty} – {purpose}',
        ]);

        $line = $this->emptyLine([
            'counterpartyName' => 'PayPal',
            'purpose' => 'Bestellung 12345',
        ]);

        $this->applicator->apply($rule, $line);

        self::assertSame(42, $line['userDebitAccountId']);
        self::assertSame(17, $line['userCreditAccountId']);
        self::assertSame(19, $line['userTaxRateId']);
        self::assertSame('Zahlung PayPal – Bestellung 12345', $line['userRemark']);
        self::assertSame(ImportState::LINE_STATUS_READY, $line['status']);
    }

    public function testTemplatePlaceholdersIncludeInvoiceNumberAndDate(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_ASSIGN,
            'remarkTemplate' => 'RG {invoiceNumber} ({date})',
        ]);

        $line = $this->emptyLine([
            'matchedInvoiceNumber' => '2026-0042',
            'valueDate' => '2026-03-15',
        ]);

        $this->applicator->apply($rule, $line);

        self::assertSame('RG 2026-0042 (2026-03-15)', $line['userRemark']);
    }

    public function testInvoiceNumberMarkerExtractionRunsBeforeTemplateRendering(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_ASSIGN,
            'debitAccountId' => 42,
            'creditAccountId' => 17,
            'remarkTemplate' => 'Lieferantenrechnung {invoiceNumber}',
            'invoiceNumberExtraction' => ['mode' => 'marker', 'marker' => 'Rechnung'],
        ]);

        $line = $this->emptyLine([
            'purpose' => 'Strom Abschlag Rechnung RE-2024-047 vom 12.03.2024',
        ]);

        $this->applicator->apply($rule, $line);

        self::assertSame('RE-2024-047', $line['userInvoiceNumber']);
        self::assertSame('Lieferantenrechnung RE-2024-047', $line['userRemark']);
        self::assertSame(ImportState::LINE_STATUS_READY, $line['status']);
    }

    public function testInvoiceNumberRegexExtractionUsesFirstCaptureGroup(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_ASSIGN,
            'invoiceNumberExtraction' => ['mode' => 'regex', 'pattern' => '/Invoice\s+(RE-\d{4}-\d{3})/i'],
        ]);

        $line = $this->emptyLine([
            'purpose' => 'Supplier Invoice RE-2024-047 paid',
        ]);

        $this->applicator->apply($rule, $line);

        self::assertSame('RE-2024-047', $line['userInvoiceNumber']);
        self::assertSame(ImportState::LINE_STATUS_READY, $line['status']);
    }

    public function testInvoiceNumberRegexExtractionFallsBackToFullMatch(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_ASSIGN,
            'invoiceNumberExtraction' => ['mode' => 'regex', 'pattern' => 'RE-\d{4}-\d{4}'],
        ]);

        $line = $this->emptyLine([
            'purpose' => 'RGN RE-1234-5678 Information zur Abrechnung Zinsen',
        ]);

        $this->applicator->apply($rule, $line);

        self::assertSame('RE-1234-5678', $line['userInvoiceNumber']);
        self::assertSame(ImportState::LINE_STATUS_READY, $line['status']);
    }

    public function testInvoiceNumberMarkerExtractionKeepsLinePendingWhenMissing(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_ASSIGN,
            'debitAccountId' => 42,
            'creditAccountId' => 17,
            'invoiceNumberExtraction' => ['mode' => 'marker', 'marker' => 'Rechnung'],
        ]);

        $line = $this->emptyLine(['purpose' => 'Strom Abschlag ohne Nummer']);

        $this->applicator->apply($rule, $line);

        self::assertSame(ImportState::LINE_STATUS_PENDING, $line['status']);
        self::assertSame('accounting.bank_import.rule.warning.invoice_marker_missing', $line['ruleWarning']['key']);
        self::assertSame(['%marker%' => 'Rechnung'], $line['ruleWarning']['params']);
        self::assertNull($line['userDebitAccountId']);
    }

    public function testInvoiceNumberRegexExtractionKeepsLinePendingWhenRegexIsInvalid(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_ASSIGN,
            'debitAccountId' => 42,
            'creditAccountId' => 17,
            'invoiceNumberExtraction' => ['mode' => 'regex', 'pattern' => '/Rechnung ([A-Z0-9-/'],
        ]);

        $line = $this->emptyLine(['purpose' => 'Rechnung RE-2024-047']);

        $this->applicator->apply($rule, $line);

        self::assertSame(ImportState::LINE_STATUS_PENDING, $line['status']);
        self::assertSame('accounting.bank_import.rule.warning.invoice_regex_invalid', $line['ruleWarning']['key']);
    }

    public function testSplitActionSplitsByFixedAmountsAndRemainder(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_SPLIT,
            'splits' => [
                ['amount' => 12.30, 'debitAccountId' => 100, 'creditAccountId' => 200, 'taxRateId' => 19, 'remarkTemplate' => 'Zinsen'],
                ['amount' => 4.20, 'debitAccountId' => 101, 'creditAccountId' => 200, 'remarkTemplate' => 'Provision'],
                ['remainder' => true, 'debitAccountId' => 102, 'creditAccountId' => 200, 'remarkTemplate' => 'Entgelte'],
            ],
        ]);

        $line = $this->emptyLine(['amount' => '-41.50']);

        $this->applicator->apply($rule, $line);

        self::assertCount(3, $line['splits']);
        self::assertSame('-12.30', $line['splits'][0]['amount']);
        self::assertSame('-4.20', $line['splits'][1]['amount']);
        // Remainder = 41.50 - 12.30 - 4.20 = 25.00.
        self::assertSame('-25.00', $line['splits'][2]['amount']);
        self::assertSame(19, $line['splits'][0]['taxRateId']);
        self::assertSame('Zinsen', $line['splits'][0]['remark']);
        self::assertSame(ImportState::LINE_STATUS_READY, $line['status']);
    }

    public function testSplitActionSupportsPercentages(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_SPLIT,
            'splits' => [
                ['percent' => 70, 'debitAccountId' => 100, 'creditAccountId' => 200],
                ['percent' => 30, 'debitAccountId' => 101, 'creditAccountId' => 200],
            ],
        ]);

        $line = $this->emptyLine(['amount' => '-100.00']);

        $this->applicator->apply($rule, $line);

        self::assertCount(2, $line['splits']);
        self::assertSame('-70.00', $line['splits'][0]['amount']);
        self::assertSame('-30.00', $line['splits'][1]['amount']);
    }

    public function testSplitActionExtractsGermanMarkerAmountsAndRemainder(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_SPLIT,
            'splits' => [
                ['amountSource' => 'purpose_marker', 'marker' => 'Zinsen', 'debitAccountId' => 100, 'creditAccountId' => 200, 'remarkTemplate' => 'Zinsen'],
                ['amountSource' => 'purpose_marker', 'marker' => 'Kreditprovision', 'debitAccountId' => 101, 'creditAccountId' => 200, 'remarkTemplate' => 'Provision'],
                ['remainder' => true, 'debitAccountId' => 102, 'creditAccountId' => 200, 'remarkTemplate' => 'Entgelte'],
            ],
        ]);

        $line = $this->emptyLine([
            'amount' => '-41.50',
            'purpose' => 'Information zur Abrechnung Zinsen fuer Kredit 12,30- Kreditprovision 4,20- Entgelte vom 23.04.2026 25,00-',
        ]);

        $this->applicator->apply($rule, $line);

        self::assertCount(3, $line['splits']);
        self::assertSame('-12.30', $line['splits'][0]['amount']);
        self::assertSame('-4.20', $line['splits'][1]['amount']);
        self::assertSame('-25.00', $line['splits'][2]['amount']);
        self::assertSame('Entgelte', $line['splits'][2]['remark']);
        self::assertSame(ImportState::LINE_STATUS_READY, $line['status']);
    }

    public function testSplitActionExtractsEnglishThousandsMarkerAmount(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_SPLIT,
            'splits' => [
                ['amountSource' => 'purpose_marker', 'marker' => 'Gebuehr', 'debitAccountId' => 100, 'creditAccountId' => 200],
            ],
        ]);

        $line = $this->emptyLine([
            'amount' => '1234.56',
            'purpose' => 'Settlement Gebuehr 1,234.56 payout',
        ]);

        $this->applicator->apply($rule, $line);

        self::assertCount(1, $line['splits']);
        self::assertSame('1234.56', $line['splits'][0]['amount']);
        self::assertSame(ImportState::LINE_STATUS_READY, $line['status']);
    }

    public function testSplitActionExtractsRegexAmount(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_SPLIT,
            'splits' => [
                ['amountSource' => 'purpose_regex', 'pattern' => 'Zinsen\\s+([\\d,]+)-', 'debitAccountId' => 100, 'creditAccountId' => 200],
            ],
        ]);

        $line = $this->emptyLine([
            'amount' => '-12.30',
            'purpose' => 'Information zur Abrechnung Zinsen 12,30- Entgelte',
        ]);

        $this->applicator->apply($rule, $line);

        self::assertCount(1, $line['splits']);
        self::assertSame('-12.30', $line['splits'][0]['amount']);
        self::assertSame(ImportState::LINE_STATUS_READY, $line['status']);
    }

    public function testSplitActionExtractsIntegerRegexAmount(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_SPLIT,
            'splits' => [
                ['amountSource' => 'purpose_regex', 'pattern' => '\\d{4}', 'debitAccountId' => 100, 'creditAccountId' => 200],
            ],
        ]);

        $line = $this->emptyLine([
            'amount' => '-2026.00',
            'purpose' => 'BOOKING.COM BV SAMMELAUSZAHLUNG APRIL 2026 3 BUCHUNGEN KOMMISSION 199,33 GEBUEHR 7,45',
        ]);

        $this->applicator->apply($rule, $line);

        self::assertCount(1, $line['splits']);
        self::assertSame('-2026.00', $line['splits'][0]['amount']);
        self::assertSame(ImportState::LINE_STATUS_READY, $line['status']);
    }

    public function testSplitActionExtractsIntegerRegexCaptureAmount(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_SPLIT,
            'splits' => [
                ['amountSource' => 'purpose_regex', 'pattern' => '([0-9]+ )', 'debitAccountId' => 100, 'creditAccountId' => 200],
            ],
        ]);

        $line = $this->emptyLine([
            'amount' => '-2026.00',
            'purpose' => 'BOOKING.COM BV SAMMELAUSZAHLUNG APRIL 2026 3 BUCHUNGEN KOMMISSION 199,33 GEBUEHR 7,45',
        ]);

        $this->applicator->apply($rule, $line);

        self::assertCount(1, $line['splits']);
        self::assertSame('-2026.00', $line['splits'][0]['amount']);
        self::assertSame(ImportState::LINE_STATUS_READY, $line['status']);
    }

    public function testSplitActionUsesFirstParseableRegexCaptureAmount(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_SPLIT,
            'splits' => [
                ['amountSource' => 'purpose_regex', 'pattern' => '(APRIL)\\s+(\\d{4})', 'debitAccountId' => 100, 'creditAccountId' => 200],
            ],
        ]);

        $line = $this->emptyLine([
            'amount' => '-2026.00',
            'purpose' => 'BOOKING.COM BV SAMMELAUSZAHLUNG APRIL 2026 3 BUCHUNGEN KOMMISSION 199,33 GEBUEHR 7,45',
        ]);

        $this->applicator->apply($rule, $line);

        self::assertCount(1, $line['splits']);
        self::assertSame('-2026.00', $line['splits'][0]['amount']);
        self::assertSame(ImportState::LINE_STATUS_READY, $line['status']);
    }

    public function testSplitActionExtractsIntegerMarkerAmountsWithCurrencyAndCommaSeparator(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_SPLIT,
            'splits' => [
                ['amountSource' => 'purpose_marker', 'marker' => 'Miete', 'debitAccountId' => 100, 'creditAccountId' => 200],
                ['amountSource' => 'purpose_marker', 'marker' => 'Nebenkosten', 'debitAccountId' => 101, 'creditAccountId' => 200],
            ],
        ]);

        $line = $this->emptyLine([
            'amount' => '-2260.00',
            'purpose' => 'Miete 1790 Euro, Nebenkosten 470, Nachname',
        ]);

        $this->applicator->apply($rule, $line);

        self::assertCount(2, $line['splits']);
        self::assertSame('-1790.00', $line['splits'][0]['amount']);
        self::assertSame('-470.00', $line['splits'][1]['amount']);
        self::assertSame(ImportState::LINE_STATUS_READY, $line['status']);
    }

    public function testDynamicSplitActionPreservesIncomingDirection(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_SPLIT,
            'splits' => [
                ['amountSource' => 'purpose_marker', 'marker' => 'Auszahlung', 'debitAccountId' => 100, 'creditAccountId' => 200],
            ],
        ]);

        $line = $this->emptyLine([
            'amount' => '230.00',
            'purpose' => 'Auszahlung 230,00 April',
        ]);

        $this->applicator->apply($rule, $line);

        self::assertSame('230.00', $line['splits'][0]['amount']);
    }

    public function testSplitActionKeepsLinePendingWhenMarkerAmountIsMissing(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_SPLIT,
            'splits' => [
                ['amountSource' => 'purpose_marker', 'marker' => 'Zinsen', 'debitAccountId' => 100, 'creditAccountId' => 200],
            ],
        ]);

        $line = $this->emptyLine([
            'amount' => '-41.50',
            'purpose' => 'Information zur Abrechnung Entgelte 41,50-',
        ]);

        $this->applicator->apply($rule, $line);

        self::assertSame([], $line['splits']);
        self::assertSame(ImportState::LINE_STATUS_PENDING, $line['status']);
        self::assertSame('accounting.bank_import.rule.warning.split_marker_missing', $line['ruleWarning']['key']);
        self::assertSame(['%marker%' => 'Zinsen'], $line['ruleWarning']['params']);
    }

    public function testSplitActionKeepsLinePendingWhenRegexAmountIsMissing(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_SPLIT,
            'splits' => [
                ['amountSource' => 'purpose_regex', 'pattern' => 'Zinsen\\s+([\\d,]+)-', 'debitAccountId' => 100, 'creditAccountId' => 200],
            ],
        ]);

        $line = $this->emptyLine([
            'amount' => '-41.50',
            'purpose' => 'Information zur Abrechnung Entgelte 41,50-',
        ]);

        $this->applicator->apply($rule, $line);

        self::assertSame([], $line['splits']);
        self::assertSame(ImportState::LINE_STATUS_PENDING, $line['status']);
        self::assertSame('accounting.bank_import.rule.warning.split_regex_missing', $line['ruleWarning']['key']);
        self::assertSame(['%pattern%' => 'Zinsen\\s+([\\d,]+)-'], $line['ruleWarning']['params']);
    }

    public function testSplitActionKeepsLinePendingWhenRegexIsInvalid(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_SPLIT,
            'splits' => [
                ['amountSource' => 'purpose_regex', 'pattern' => 'Zinsen\\s+([\\d,]+-', 'debitAccountId' => 100, 'creditAccountId' => 200],
            ],
        ]);

        $line = $this->emptyLine([
            'amount' => '-41.50',
            'purpose' => 'Information zur Abrechnung Zinsen 12,30-',
        ]);

        $this->applicator->apply($rule, $line);

        self::assertSame([], $line['splits']);
        self::assertSame(ImportState::LINE_STATUS_PENDING, $line['status']);
        self::assertSame('accounting.bank_import.rule.warning.split_regex_invalid', $line['ruleWarning']['key']);
        self::assertSame(['%pattern%' => 'Zinsen\\s+([\\d,]+-'], $line['ruleWarning']['params']);
    }

    public function testSplitActionPreservesIncomingDirection(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_SPLIT,
            'splits' => [
                ['amount' => 80, 'debitAccountId' => 100, 'creditAccountId' => 200],
                ['remainder' => true, 'debitAccountId' => 101, 'creditAccountId' => 200],
            ],
        ]);

        $line = $this->emptyLine(['amount' => '120.00']);

        $this->applicator->apply($rule, $line);

        self::assertSame('80.00', $line['splits'][0]['amount']);
        self::assertSame('40.00', $line['splits'][1]['amount']);
    }

    public function testEmptySplitsConfigIsNoOp(): void
    {
        $rule = $this->createRule([
            'mode' => BankImportRule::ACTION_MODE_SPLIT,
            'splits' => [],
        ]);

        $line = $this->emptyLine(['amount' => '-50.00']);

        $this->applicator->apply($rule, $line);

        self::assertSame([], $line['splits']);
        self::assertSame(ImportState::LINE_STATUS_PENDING, $line['status']);
    }

    /**
     * @param array<string, mixed> $action
     */
    private function createRule(array $action): BankImportRule
    {
        $rule = new BankImportRule();
        $rule->setName('test');
        $rule->setAction($action);

        // Reflection: assign id without going through the database.
        (new \ReflectionClass($rule))->getProperty('id')->setValue($rule, 7);

        return $rule;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function emptyLine(array $overrides = []): array
    {
        return array_merge([
            'amount' => '0.00',
            'counterpartyName' => '',
            'counterpartyIban' => null,
            'purpose' => '',
            'bookDate' => '2026-03-15',
            'valueDate' => '2026-03-15',
            'matchedInvoiceNumber' => null,
            'status' => ImportState::LINE_STATUS_PENDING,
            'isIgnored' => false,
            'isDuplicate' => false,
            'userDebitAccountId' => null,
            'userCreditAccountId' => null,
            'userTaxRateId' => null,
            'userRemark' => null,
            'userInvoiceNumber' => null,
            'appliedRuleId' => null,
            'splits' => [],
        ], $overrides);
    }
}
