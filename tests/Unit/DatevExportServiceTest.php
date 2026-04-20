<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AccountingAccount;
use App\Entity\AccountingSettings;
use App\Entity\BookingBatch;
use App\Entity\BookingEntry;
use App\Entity\TaxRate;
use App\Service\JournalExport\DatevExportService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DatevExportServiceTest extends TestCase
{
    private DatevExportService $service;

    protected function setUp(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            fn (string $id, array $params = []) => strtr($id, $params),
        );
        $this->service = new DatevExportService($translator);
    }

    // ── Interface ──────────────────────────────────────────────────

    public function testFormatMetadata(): void
    {
        self::assertSame('DATEV', $this->service->getFormatName());
        self::assertSame('csv', $this->service->getFileExtension());
        self::assertSame('text/csv', $this->service->getMimeType());
    }

    // ── Header line ────────────────────────────────────────────────

    public function testHeaderLineContainsExtfMarker(): void
    {
        $csv = $this->exportSample();
        $headerLine = $this->getLine($csv, 0);

        self::assertStringStartsWith("\xEF\xBB\xBF\"EXTF\";700;21;\"Buchungsstapel\";13;", $headerLine);
    }

    public function testHeaderContainsAdvisorAndClientNumber(): void
    {
        $csv = $this->exportSample(advisorNumber: '29098', clientNumber: '55003');
        $headerLine = $this->getLine($csv, 0);
        $fields = $this->parseLine($headerLine);

        self::assertSame('29098', $fields[10]);
        self::assertSame('55003', $fields[11]);
    }

    public function testHeaderContainsFiscalYearStartAndAccountLength(): void
    {
        $csv = $this->exportSample(fiscalYearStart: 7, accountNumberLength: 5);
        $fields = $this->parseLine($this->getLine($csv, 0));

        // Fiscal year start: YYYYMMDD with month from settings
        self::assertStringEndsWith('0701', $fields[12]);
        self::assertSame('5', $fields[13]);
    }

    public function testHeaderContainsPeriodDates(): void
    {
        $csv = $this->exportSample(year: 2026, month: 3);
        $fields = $this->parseLine($this->getLine($csv, 0));

        self::assertSame('20260301', $fields[14]);
        self::assertSame('20260331', $fields[15]);
    }

    public function testHeaderContainsCurrency(): void
    {
        $csv = $this->exportSample(currency: 'CHF');
        $fields = $this->parseLine($this->getLine($csv, 0));

        self::assertSame('CHF', $fields[21]);
    }

    public function testHeaderContainsSkrType(): void
    {
        $csv = $this->exportSample(chartPreset: AccountingSettings::PRESET_SKR03);
        $fields = $this->parseLine($this->getLine($csv, 0));

        self::assertSame('03', $fields[26]);
    }

    public function testHeaderContainsSkr04Type(): void
    {
        $csv = $this->exportSample(chartPreset: AccountingSettings::PRESET_SKR04);
        $fields = $this->parseLine($this->getLine($csv, 0));

        self::assertSame('04', $fields[26]);
    }

    public function testHeaderSkrTypeEmptyForNonGermanPreset(): void
    {
        $csv = $this->exportSample(chartPreset: AccountingSettings::PRESET_EKR_AT);
        $fields = $this->parseLine($this->getLine($csv, 0));

        self::assertSame('', $fields[26]);
    }

    public function testHeaderContainsDictationCode(): void
    {
        $csv = $this->exportSample(dictationCode: 'AB');
        $fields = $this->parseLine($this->getLine($csv, 0));

        self::assertSame('AB', $fields[17]);
    }

    // ── Column header line ─────────────────────────────────────────

    public function testColumnHeaderLine(): void
    {
        $csv = $this->exportSample();
        $columnLine = $this->getLine($csv, 1);

        self::assertStringStartsWith('Umsatz (ohne Soll/Haben-Kz);Soll/Haben-Kennzeichen;', $columnLine);
        self::assertCount(125, $this->parseLine($columnLine));
    }

    public function testColumnHeaderLineMatchesExpectedDatevColumns(): void
    {
        $csv = $this->exportSample();

        self::assertSame($this->expectedColumnHeaders(), $this->parseLine($this->getLine($csv, 1)));
    }

    // ── Data lines ─────────────────────────────────────────────────

    public function testDataLineAmount(): void
    {
        $entry = $this->createEntry(amount: '119.00');
        $csv = $this->exportWithEntry($entry);
        $fields = $this->parseLine($this->getLine($csv, 2));

        self::assertSame('119,00', $fields[0]);
        self::assertSame('S', $fields[1]);
    }

    public function testDataLineAccounts(): void
    {
        $debit = $this->createAccount('1000', 'Kasse');
        $credit = $this->createAccount('8300', 'Erlöse 7%');

        $entry = $this->createEntry(amount: '50.00', debit: $debit, credit: $credit);
        $csv = $this->exportWithEntry($entry);
        $fields = $this->parseLine($this->getLine($csv, 2));

        self::assertSame('1000', $fields[6]);
        self::assertSame('8300', $fields[7]);
    }

    public function testDataLineTaxBuKeyOutputForRevenueBooking(): void
    {
        $debit = $this->createAccount('1000', 'Kasse', AccountingAccount::TYPE_ASSET);
        $credit = $this->createAccount('8400', 'Erlöse 19%', AccountingAccount::TYPE_REVENUE);
        $taxRate = $this->createTaxRate('19% USt', '19.00', outputBuKey: '3', inputBuKey: '9');

        $entry = $this->createEntry(amount: '119.00', debit: $debit, credit: $credit, taxRate: $taxRate);
        $fields = $this->parseLine($this->getLine($this->exportWithEntry($entry), 2));

        self::assertSame('3', $fields[8]);
    }

    public function testDataLineTaxBuKeyInputForExpenseBooking(): void
    {
        $debit = $this->createAccount('4930', 'Bürobedarf', AccountingAccount::TYPE_EXPENSE);
        $credit = $this->createAccount('1200', 'Bank', AccountingAccount::TYPE_ASSET);
        $taxRate = $this->createTaxRate('19% USt', '19.00', outputBuKey: '3', inputBuKey: '9');

        $entry = $this->createEntry(amount: '119.00', debit: $debit, credit: $credit, taxRate: $taxRate);
        $fields = $this->parseLine($this->getLine($this->exportWithEntry($entry), 2));

        self::assertSame('9', $fields[8]);
    }

    public function testDataLineBuKeySuppressedForAutomatikkonto(): void
    {
        $debit = $this->createAccount('1000', 'Kasse', AccountingAccount::TYPE_ASSET);
        $credit = $this->createAccount('8400', 'Erlöse 19%', AccountingAccount::TYPE_REVENUE);
        $credit->setIsAutoAccount(true);
        $taxRate = $this->createTaxRate('19% USt', '19.00', outputBuKey: '3', inputBuKey: '9');

        $entry = $this->createEntry(amount: '119.00', debit: $debit, credit: $credit, taxRate: $taxRate);
        $fields = $this->parseLine($this->getLine($this->exportWithEntry($entry), 2));

        self::assertSame('', $fields[8]);
    }

    public function testDataLineLuLFieldsPopulatedFromAccount(): void
    {
        $debit = $this->createAccount('3123', 'Bezogene Leistungen §13b 19%', AccountingAccount::TYPE_EXPENSE);
        $debit->setIsAutoAccount(true);
        $debit->setDatevSachverhaltLuL(7);
        $debit->setDatevFunktionsergaenzungLuL(190);
        $credit = $this->createAccount('1200', 'Bank', AccountingAccount::TYPE_ASSET);

        $entry = $this->createEntry(amount: '119.00', debit: $debit, credit: $credit);
        $fields = $this->parseLine($this->getLine($this->exportWithEntry($entry), 2));

        self::assertSame('7', $fields[42]);
        self::assertSame('190', $fields[43]);
    }

    public function testDataLineLuLFieldsEmptyWhenNotConfigured(): void
    {
        $debit = $this->createAccount('1000', 'Kasse', AccountingAccount::TYPE_ASSET);
        $credit = $this->createAccount('8400', 'Erlöse 19%', AccountingAccount::TYPE_REVENUE);

        $entry = $this->createEntry(amount: '119.00', debit: $debit, credit: $credit);
        $fields = $this->parseLine($this->getLine($this->exportWithEntry($entry), 2));

        self::assertSame('', $fields[42]);
        self::assertSame('', $fields[43]);
    }

    public function testDataLineDateFormat(): void
    {
        $entry = $this->createEntry(amount: '10.00', date: new \DateTime('2026-03-15'));
        $csv = $this->exportWithEntry($entry);
        $fields = $this->parseLine($this->getLine($csv, 2));

        // DDMM format
        self::assertSame('1503', $fields[9]);
    }

    public function testDataLineInvoiceNumber(): void
    {
        $entry = $this->createEntry(amount: '10.00');
        $entry->setInvoiceNumber('R-2026-0042');
        $csv = $this->exportWithEntry($entry);
        $fields = $this->parseLine($this->getLine($csv, 2));

        self::assertSame('R-2026-0042', $fields[10]);
    }

    public function testDataLineRemarkTruncatedTo60Chars(): void
    {
        $longRemark = str_repeat('A', 80);
        $entry = $this->createEntry(amount: '10.00');
        $entry->setRemark($longRemark);

        $csv = $this->exportWithEntry($entry);
        $fields = $this->parseLine($this->getLine($csv, 2));

        // Remove surrounding quotes
        $remark = trim($fields[13], '"');
        self::assertSame(60, mb_strlen($remark));
    }

    public function testDataLineHas125Columns(): void
    {
        $entry = $this->createEntry(amount: '10.00');
        $csv = $this->exportWithEntry($entry);
        $fields = $this->parseLine($this->getLine($csv, 2));

        self::assertCount(125, $fields);
    }

    public function testDataLineQuotesTextFields(): void
    {
        $debit = $this->createAccount('1000', 'Kasse', AccountingAccount::TYPE_ASSET);
        $credit = $this->createAccount('8300', 'Erlöse 7%', AccountingAccount::TYPE_REVENUE);
        $taxRate = $this->createTaxRate('7% USt', '7.00', outputBuKey: '2', inputBuKey: '8');
        $entry = $this->createEntry(amount: '100.00', debit: $debit, credit: $credit, taxRate: $taxRate);
        $entry->setInvoiceNumber('AR-42');
        $entry->setRemark('Test');

        $csv = $this->exportWithEntry($entry);
        $line = $this->getLine($csv, 2);

        self::assertStringContainsString(';"S";', $line);
        self::assertStringContainsString(';"2";', $line);
        self::assertStringContainsString(';"AR-42";', $line);
        self::assertStringContainsString(';"Test"', $line);
    }

    public function testDataLineEscapesQuotesAndSemicolonsInTextFields(): void
    {
        $taxRate = $this->createTaxRate('7% USt', '7.00', outputBuKey: '2', inputBuKey: '8');
        $entry = $this->createEntry(amount: '100.00', taxRate: $taxRate);
        $entry->setInvoiceNumber('AR;"42"');
        $entry->setRemark('Text mit ; und "Quote"');

        $csv = $this->exportWithEntry($entry);
        $line = $this->getLine($csv, 2);
        $fields = $this->parseLine($line);

        self::assertSame('AR;"42"', $fields[10]);
        self::assertSame('Text mit ; und "Quote"', $fields[13]);
        self::assertStringContainsString('"AR;""42"""', $line);
        self::assertStringContainsString('"Text mit ; und ""Quote"""', $line);
    }

    public function testMultipleEntriesProduceMultipleLines(): void
    {
        $batch = $this->createBatch();
        $batch->addEntry($this->createEntry(amount: '100.00'));
        $batch->addEntry($this->createEntry(amount: '200.00'));

        $csv = $this->service->export($batch, $this->createSettings(), 'EUR');
        $lines = $this->getLines($csv);

        // Header + column header + 2 data lines
        self::assertCount(4, $lines);
    }

    // ── Encoding ───────────────────────────────────────────────────

    public function testOutputIsUtf8Encoded(): void
    {
        $entry = $this->createEntry(amount: '10.00');
        $entry->setRemark('Übernachtung Müller');

        $csv = $this->exportWithEntry($entry);

        self::assertTrue(mb_check_encoding($csv, 'UTF-8'));
        self::assertStringContainsString('Übernachtung Müller', $csv);
    }

    public function testOutputStartsWithUtf8Bom(): void
    {
        $csv = $this->exportSample();

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    public function testLineEndingsAreCrLf(): void
    {
        $csv = $this->exportSample();

        self::assertStringContainsString("\r\n", $csv);
        // Should not have bare \n without preceding \r
        $withoutCrLf = str_replace("\r\n", '', $csv);
        self::assertStringNotContainsString("\n", $withoutCrLf);
    }

    // ── Validation ─────────────────────────────────────────────────

    public function testValidateReturnsWarningForMissingDebitAccount(): void
    {
        $entry = $this->createEntry(amount: '100.00');
        // debit is null by default
        $batch = $this->createBatch();
        $batch->addEntry($entry);

        $warnings = $this->service->validate($batch);

        self::assertNotEmpty($warnings);
        self::assertStringContainsString('no_debit', $warnings[0]);
    }

    public function testValidateReturnsWarningForMissingOutputBuKeyOnRevenueBooking(): void
    {
        $debit = $this->createAccount('1000', 'Kasse', AccountingAccount::TYPE_ASSET);
        $credit = $this->createAccount('8300', 'Erlöse', AccountingAccount::TYPE_REVENUE);
        $taxRate = $this->createTaxRate('7% USt', '7.00', outputBuKey: null, inputBuKey: '8');

        $entry = $this->createEntry(amount: '100.00', debit: $debit, credit: $credit, taxRate: $taxRate);
        $batch = $this->createBatch();
        $batch->addEntry($entry);

        $warnings = $this->service->validate($batch);

        self::assertNotEmpty($warnings);
        self::assertStringContainsString('no_output_bukey', $warnings[0]);
    }

    public function testValidateReturnsWarningForMissingInputBuKeyOnExpenseBooking(): void
    {
        $debit = $this->createAccount('4930', 'Bürobedarf', AccountingAccount::TYPE_EXPENSE);
        $credit = $this->createAccount('1200', 'Bank', AccountingAccount::TYPE_ASSET);
        $taxRate = $this->createTaxRate('19% USt', '19.00', outputBuKey: '3', inputBuKey: null);

        $entry = $this->createEntry(amount: '119.00', debit: $debit, credit: $credit, taxRate: $taxRate);
        $batch = $this->createBatch();
        $batch->addEntry($entry);

        $warnings = $this->service->validate($batch);

        self::assertNotEmpty($warnings);
        self::assertStringContainsString('no_input_bukey', $warnings[0]);
    }

    public function testValidateSuppressesBuKeyWarningForAutomatikkonto(): void
    {
        $debit = $this->createAccount('1000', 'Kasse', AccountingAccount::TYPE_ASSET);
        $credit = $this->createAccount('8400', 'Erlöse 19%', AccountingAccount::TYPE_REVENUE);
        $credit->setIsAutoAccount(true);
        $taxRate = $this->createTaxRate('19% USt', '19.00', outputBuKey: null, inputBuKey: null);

        $entry = $this->createEntry(amount: '119.00', debit: $debit, credit: $credit, taxRate: $taxRate);
        $batch = $this->createBatch();
        $batch->addEntry($entry);

        $warnings = $this->service->validate($batch);

        self::assertEmpty($warnings);
    }

    public function testValidateReturnsWarningForMissingCreditAccount(): void
    {
        $entry = $this->createEntry(amount: '100.00', debit: $this->createAccount('1000', 'Kasse'));
        $batch = $this->createBatch();
        $batch->addEntry($entry);

        $warnings = $this->service->validate($batch);

        self::assertNotEmpty($warnings);
        self::assertStringContainsString('no_credit', $warnings[0]);
    }

    public function testValidateWithSettingsReturnsWarningForMissingAdvisorNumber(): void
    {
        $settings = $this->createSettings(advisorNumber: '', clientNumber: '67890');

        $warnings = $this->service->validateWithSettings($this->createBatch(), $settings);

        self::assertContains('accounting.journal.export.warn.missing_advisor_number', $warnings);
    }

    public function testValidateWithSettingsReturnsWarningForMissingClientNumber(): void
    {
        $settings = $this->createSettings(advisorNumber: '12345', clientNumber: '');

        $warnings = $this->service->validateWithSettings($this->createBatch(), $settings);

        self::assertContains('accounting.journal.export.warn.missing_client_number', $warnings);
    }

    public function testValidateWithSettingsAccumulatesSettingsAndEntryWarnings(): void
    {
        $settings = $this->createSettings(advisorNumber: '', clientNumber: '');
        $entry = $this->createEntry(amount: '100.00');
        $batch = $this->createBatch();
        $batch->addEntry($entry);

        $warnings = $this->service->validateWithSettings($batch, $settings);

        self::assertContains('accounting.journal.export.warn.missing_advisor_number', $warnings);
        self::assertContains('accounting.journal.export.warn.missing_client_number', $warnings);
        self::assertTrue($this->containsWarningFragment($warnings, 'no_debit'));
        self::assertTrue($this->containsWarningFragment($warnings, 'no_credit'));
    }

    public function testTextColumnIndexesMatchExpectedDatevTemplate(): void
    {
        $reflection = new \ReflectionClass(DatevExportService::class);
        $constant = $reflection->getReflectionConstant('TEXT_COLUMN_INDEXES');

        self::assertNotFalse($constant);
        self::assertSame($this->expectedTextColumnIndexes(), array_keys($constant->getValue()));
    }

    public function testValidateReturnsNoWarningsForValidEntry(): void
    {
        $debit = $this->createAccount('1000', 'Kasse');
        $credit = $this->createAccount('8300', 'Erlöse');
        $taxRate = $this->createTaxRate('7% USt', '7.00', '2');

        $entry = $this->createEntry(amount: '100.00', debit: $debit, credit: $credit, taxRate: $taxRate);
        $batch = $this->createBatch();
        $batch->addEntry($entry);

        $warnings = $this->service->validate($batch);

        self::assertEmpty($warnings);
    }

    public function testEmptyBatchProducesOnlyHeaderAndColumnLine(): void
    {
        $batch = $this->createBatch();
        $csv = $this->service->export($batch, $this->createSettings(), 'EUR');
        $lines = $this->getLines($csv);

        self::assertCount(2, $lines);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function exportSample(
        string $advisorNumber = '12345',
        string $clientNumber = '67890',
        int $fiscalYearStart = 1,
        int $accountNumberLength = 4,
        ?string $chartPreset = AccountingSettings::PRESET_SKR03,
        ?string $dictationCode = 'WD',
        int $year = 2026,
        int $month = 4,
        string $currency = 'EUR',
    ): string {
        $batch = $this->createBatch($year, $month);
        $settings = $this->createSettings($advisorNumber, $clientNumber, $fiscalYearStart, $accountNumberLength, $chartPreset, $dictationCode);

        return $this->service->export($batch, $settings, $currency);
    }

    private function exportWithEntry(BookingEntry $entry): string
    {
        $batch = $this->createBatch();
        $batch->addEntry($entry);

        return $this->service->export($batch, $this->createSettings(), 'EUR');
    }

    private function createBatch(int $year = 2026, int $month = 4): BookingBatch
    {
        $batch = new BookingBatch();
        $batch->setYear($year);
        $batch->setMonth($month);

        return $batch;
    }

    private function createSettings(
        string $advisorNumber = '12345',
        string $clientNumber = '67890',
        int $fiscalYearStart = 1,
        int $accountNumberLength = 4,
        ?string $chartPreset = AccountingSettings::PRESET_SKR03,
        ?string $dictationCode = 'WD',
    ): AccountingSettings {
        $settings = new AccountingSettings();
        $settings->setAdvisorNumber($advisorNumber);
        $settings->setClientNumber($clientNumber);
        $settings->setFiscalYearStart($fiscalYearStart);
        $settings->setAccountNumberLength($accountNumberLength);
        $settings->setChartPreset($chartPreset);
        $settings->setDictationCode($dictationCode);

        return $settings;
    }

    private function createEntry(
        string $amount = '0.00',
        ?AccountingAccount $debit = null,
        ?AccountingAccount $credit = null,
        ?TaxRate $taxRate = null,
        ?\DateTime $date = null,
    ): BookingEntry {
        $entry = new BookingEntry();
        $entry->setAmount($amount);
        $entry->setDocumentNumber(1);
        $entry->setDate($date ?? new \DateTime('2026-04-15'));

        if (null !== $debit) {
            $entry->setDebitAccount($debit);
        }
        if (null !== $credit) {
            $entry->setCreditAccount($credit);
        }
        if (null !== $taxRate) {
            $entry->setTaxRate($taxRate);
        }

        return $entry;
    }

    private function createAccount(string $number, string $name, string $type = AccountingAccount::TYPE_ASSET): AccountingAccount
    {
        $account = new AccountingAccount();
        $account->setAccountNumber($number);
        $account->setName($name);
        $account->setType($type);

        return $account;
    }

    private function createTaxRate(string $name, string $rate, ?string $outputBuKey, ?string $inputBuKey = null): TaxRate
    {
        $taxRate = new TaxRate();
        $taxRate->setName($name);
        $taxRate->setRate($rate);
        $taxRate->setDatevOutputBuKey($outputBuKey);
        $taxRate->setDatevInputBuKey($inputBuKey);

        return $taxRate;
    }

    /**
     * @return string[]
     */
    private function getLines(string $csv): array
    {
        $lines = explode("\r\n", trim($csv, "\r\n"));

        return array_filter($lines, fn (string $l) => '' !== $l);
    }

    private function getLine(string $csv, int $index): string
    {
        return $this->getLines($csv)[$index];
    }

    /**
     * @return string[]
     */
    private function parseLine(string $line): array
    {
        return str_getcsv($line, ';', '"', '\\');
    }

    /**
     * @return string[]
     */
    private function expectedColumnHeaders(): array
    {
        return [
            'Umsatz (ohne Soll/Haben-Kz)',
            'Soll/Haben-Kennzeichen',
            'WKZ Umsatz',
            'Kurs',
            'Basis-Umsatz',
            'WKZ Basis-Umsatz',
            'Konto',
            'Gegenkonto (ohne BU-Schlüssel)',
            'BU-Schlüssel',
            'Belegdatum',
            'Belegfeld 1',
            'Belegfeld 2',
            'Skonto',
            'Buchungstext',
            'Postensperre',
            'Diverse Adressnummer',
            'Geschäftspartnerbank',
            'Sachverhalt',
            'Zinssperre',
            'Beleglink',
            'Beleginfo - Art 1',
            'Beleginfo - Inhalt 1',
            'Beleginfo - Art 2',
            'Beleginfo - Inhalt 2',
            'Beleginfo - Art 3',
            'Beleginfo - Inhalt 3',
            'Beleginfo - Art 4',
            'Beleginfo - Inhalt 4',
            'Beleginfo - Art 5',
            'Beleginfo - Inhalt 5',
            'Beleginfo - Art 6',
            'Beleginfo - Inhalt 6',
            'Beleginfo - Art 7',
            'Beleginfo - Inhalt 7',
            'Beleginfo - Art 8',
            'Beleginfo - Inhalt 8',
            'KOST1 - Kostenstelle',
            'KOST2 - Kostenstelle',
            'Kost-Menge',
            'EU-Land u. UStID (Bestimmung)',
            'EU-Steuersatz (Bestimmung)',
            'Abw. Versteuerungsart',
            'Sachverhalt L+L',
            'Funktionsergänzung L+L',
            'BU 49 Hauptfunktionstyp',
            'BU 49 Hauptfunktionsnummer',
            'BU 49 Funktionsergänzung',
            'Zusatzinformation - Art 1',
            'Zusatzinformation- Inhalt 1',
            'Zusatzinformation - Art 2',
            'Zusatzinformation- Inhalt 2',
            'Zusatzinformation - Art 3',
            'Zusatzinformation- Inhalt 3',
            'Zusatzinformation - Art 4',
            'Zusatzinformation- Inhalt 4',
            'Zusatzinformation - Art 5',
            'Zusatzinformation- Inhalt 5',
            'Zusatzinformation - Art 6',
            'Zusatzinformation- Inhalt 6',
            'Zusatzinformation - Art 7',
            'Zusatzinformation- Inhalt 7',
            'Zusatzinformation - Art 8',
            'Zusatzinformation- Inhalt 8',
            'Zusatzinformation - Art 9',
            'Zusatzinformation- Inhalt 9',
            'Zusatzinformation - Art 10',
            'Zusatzinformation- Inhalt 10',
            'Zusatzinformation - Art 11',
            'Zusatzinformation- Inhalt 11',
            'Zusatzinformation - Art 12',
            'Zusatzinformation- Inhalt 12',
            'Zusatzinformation - Art 13',
            'Zusatzinformation- Inhalt 13',
            'Zusatzinformation - Art 14',
            'Zusatzinformation- Inhalt 14',
            'Zusatzinformation - Art 15',
            'Zusatzinformation- Inhalt 15',
            'Zusatzinformation - Art 16',
            'Zusatzinformation- Inhalt 16',
            'Zusatzinformation - Art 17',
            'Zusatzinformation- Inhalt 17',
            'Zusatzinformation - Art 18',
            'Zusatzinformation- Inhalt 18',
            'Zusatzinformation - Art 19',
            'Zusatzinformation- Inhalt 19',
            'Zusatzinformation - Art 20',
            'Zusatzinformation- Inhalt 20',
            'Stück',
            'Gewicht',
            'Zahlweise',
            'Forderungsart',
            'Veranlagungsjahr',
            'Zugeordnete Fälligkeit',
            'Skontotyp',
            'Auftragsnummer',
            'Buchungstyp',
            'USt-Schlüssel (Anzahlungen)',
            'EU-Land (Anzahlungen)',
            'Sachverhalt L+L (Anzahlungen)',
            'EU-Steuersatz (Anzahlungen)',
            'Erlöskonto (Anzahlungen)',
            'Herkunft-Kz',
            'Buchungs GUID',
            'KOST-Datum',
            'SEPA-Mandatsreferenz',
            'Skontosperre',
            'Gesellschaftername',
            'Beteiligtennummer',
            'Identifikationsnummer',
            'Zeichnernummer',
            'Postensperre bis',
            'Bezeichnung SoBil-Sachverhalt',
            'Kennzeichen SoBil-Buchung',
            'Festschreibung',
            'Leistungsdatum',
            'Datum Zuord. Steuerperiode',
            'Fälligkeit',
            'Generalumkehr (GU)',
            'Steuersatz',
            'Land',
            'Abrechnungsreferenz',
            'BVV-Position',
            'EU-Land u. UStID (Ursprung)',
            'EU-Steuersatz (Ursprung)',
            'Abw. Skontokonto',
        ];
    }

    /**
     * @return int[]
     */
    private function expectedTextColumnIndexes(): array
    {
        return [
            1, 2, 5, 8, 10, 11, 13, 14, 15, 17, 19, 20, 21, 22, 23, 24, 25,
            26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 39, 41, 42, 43,
            44, 46, 47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58, 59, 60,
            61, 62, 63, 64, 65, 66, 67, 68, 69, 70, 71, 72, 73, 74, 75, 76,
            77, 78, 79, 80, 81, 82, 83, 84, 85, 86, 89, 90, 93, 94, 95, 96,
            97, 98, 100, 101, 102, 104, 105, 106, 108, 109, 111, 112, 113,
            117, 119, 120, 122, 123, 124,
        ];
    }

    /**
     * @param string[] $warnings
     */
    private function containsWarningFragment(array $warnings, string $fragment): bool
    {
        foreach ($warnings as $warning) {
            if (str_contains($warning, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
