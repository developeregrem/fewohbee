<?php

declare(strict_types=1);

namespace App\Service\JournalExport;

use App\Entity\AccountingAccount;
use App\Entity\AccountingSettings;
use App\Entity\BookingBatch;
use App\Entity\BookingEntry;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Exports a BookingBatch as DATEV Buchungsstapel CSV (EXTF format).
 *
 * Format: semicolon-separated, UTF-8 encoding, \r\n line endings.
 * Spec version: EXTF 700, data category 21, format version 13.
 */
class DatevExportService implements BookingExportInterface
{
    private const UTF8_BOM = "\xEF\xBB\xBF";
    private const EXTF_VERSION = 700;
    private const DATA_CATEGORY = 21;
    private const FORMAT_NAME = 'Buchungsstapel';
    private const FORMAT_VERSION = 13;
    private const COLUMN_COUNT = 125;

    private const COLUMN_HEADERS = [
        'Umsatz (ohne Soll/Haben-Kz)', 'Soll/Haben-Kennzeichen', 'WKZ Umsatz', 'Kurs',
        'Basis-Umsatz', 'WKZ Basis-Umsatz', 'Konto', 'Gegenkonto (ohne BU-Schlüssel)',
        'BU-Schlüssel', 'Belegdatum', 'Belegfeld 1', 'Belegfeld 2',
        'Skonto', 'Buchungstext', 'Postensperre', 'Diverse Adressnummer',
        'Geschäftspartnerbank', 'Sachverhalt', 'Zinssperre', 'Beleglink',
        'Beleginfo - Art 1', 'Beleginfo - Inhalt 1', 'Beleginfo - Art 2', 'Beleginfo - Inhalt 2',
        'Beleginfo - Art 3', 'Beleginfo - Inhalt 3', 'Beleginfo - Art 4', 'Beleginfo - Inhalt 4',
        'Beleginfo - Art 5', 'Beleginfo - Inhalt 5', 'Beleginfo - Art 6', 'Beleginfo - Inhalt 6',
        'Beleginfo - Art 7', 'Beleginfo - Inhalt 7', 'Beleginfo - Art 8', 'Beleginfo - Inhalt 8',
        'KOST1 - Kostenstelle', 'KOST2 - Kostenstelle', 'Kost-Menge', 'EU-Land u. UStID (Bestimmung)',
        'EU-Steuersatz (Bestimmung)', 'Abw. Versteuerungsart', 'Sachverhalt L+L', 'Funktionsergänzung L+L',
        'BU 49 Hauptfunktionstyp', 'BU 49 Hauptfunktionsnummer', 'BU 49 Funktionsergänzung', 'Zusatzinformation - Art 1',
        'Zusatzinformation- Inhalt 1', 'Zusatzinformation - Art 2', 'Zusatzinformation- Inhalt 2', 'Zusatzinformation - Art 3',
        'Zusatzinformation- Inhalt 3', 'Zusatzinformation - Art 4', 'Zusatzinformation- Inhalt 4', 'Zusatzinformation - Art 5',
        'Zusatzinformation- Inhalt 5', 'Zusatzinformation - Art 6', 'Zusatzinformation- Inhalt 6', 'Zusatzinformation - Art 7',
        'Zusatzinformation- Inhalt 7', 'Zusatzinformation - Art 8', 'Zusatzinformation- Inhalt 8', 'Zusatzinformation - Art 9',
        'Zusatzinformation- Inhalt 9', 'Zusatzinformation - Art 10', 'Zusatzinformation- Inhalt 10', 'Zusatzinformation - Art 11',
        'Zusatzinformation- Inhalt 11', 'Zusatzinformation - Art 12', 'Zusatzinformation- Inhalt 12', 'Zusatzinformation - Art 13',
        'Zusatzinformation- Inhalt 13', 'Zusatzinformation - Art 14', 'Zusatzinformation- Inhalt 14', 'Zusatzinformation - Art 15',
        'Zusatzinformation- Inhalt 15', 'Zusatzinformation - Art 16', 'Zusatzinformation- Inhalt 16', 'Zusatzinformation - Art 17',
        'Zusatzinformation- Inhalt 17', 'Zusatzinformation - Art 18', 'Zusatzinformation- Inhalt 18', 'Zusatzinformation - Art 19',
        'Zusatzinformation- Inhalt 19', 'Zusatzinformation - Art 20', 'Zusatzinformation- Inhalt 20', 'Stück',
        'Gewicht', 'Zahlweise', 'Forderungsart', 'Veranlagungsjahr',
        'Zugeordnete Fälligkeit', 'Skontotyp', 'Auftragsnummer', 'Buchungstyp',
        'USt-Schlüssel (Anzahlungen)', 'EU-Land (Anzahlungen)', 'Sachverhalt L+L (Anzahlungen)', 'EU-Steuersatz (Anzahlungen)',
        'Erlöskonto (Anzahlungen)', 'Herkunft-Kz', 'Buchungs GUID', 'KOST-Datum',
        'SEPA-Mandatsreferenz', 'Skontosperre', 'Gesellschaftername', 'Beteiligtennummer',
        'Identifikationsnummer', 'Zeichnernummer', 'Postensperre bis', 'Bezeichnung SoBil-Sachverhalt',
        'Kennzeichen SoBil-Buchung', 'Festschreibung', 'Leistungsdatum', 'Datum Zuord. Steuerperiode',
        'Fälligkeit', 'Generalumkehr (GU)', 'Steuersatz', 'Land',
        'Abrechnungsreferenz', 'BVV-Position', 'EU-Land u. UStID (Ursprung)', 'EU-Steuersatz (Ursprung)',
        'Abw. Skontokonto',
    ];

    private const TEXT_COLUMN_INDEXES = [
        1 => true, 2 => true, 5 => true, 8 => true, 10 => true, 11 => true, 13 => true, 14 => true,
        15 => true, 17 => true, 19 => true, 20 => true, 21 => true, 22 => true, 23 => true, 24 => true,
        25 => true, 26 => true, 27 => true, 28 => true, 29 => true, 30 => true, 31 => true, 32 => true,
        33 => true, 34 => true, 35 => true, 36 => true, 37 => true, 39 => true, 41 => true, 42 => true,
        43 => true, 44 => true, 46 => true, 47 => true, 48 => true, 49 => true, 50 => true, 51 => true,
        52 => true, 53 => true, 54 => true, 55 => true, 56 => true, 57 => true, 58 => true, 59 => true,
        60 => true, 61 => true, 62 => true, 63 => true, 64 => true, 65 => true, 66 => true, 67 => true,
        68 => true, 69 => true, 70 => true, 71 => true, 72 => true, 73 => true, 74 => true, 75 => true,
        76 => true, 77 => true, 78 => true, 79 => true, 80 => true, 81 => true, 82 => true, 83 => true,
        84 => true, 85 => true, 86 => true, 89 => true, 90 => true, 93 => true, 94 => true, 95 => true,
        96 => true, 97 => true, 98 => true, 100 => true, 101 => true, 102 => true, 104 => true, 105 => true,
        106 => true, 108 => true, 109 => true, 111 => true, 112 => true, 113 => true, 117 => true, 119 => true,
        120 => true, 122 => true, 123 => true, 124 => true,
    ];

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFormatName(): string
    {
        return 'DATEV';
    }

    public function getFileExtension(): string
    {
        return 'csv';
    }

    public function getMimeType(): string
    {
        return 'text/csv';
    }

    public function export(BookingBatch $batch, AccountingSettings $settings, string $currency = 'EUR'): string
    {
        $periodStart = sprintf('%d%02d01', $batch->getYear(), $batch->getMonth());
        $periodEnd = (new \DateTimeImmutable($periodStart))->format('Ymt');

        $lines = [];
        $lines[] = $this->buildHeaderLine(
            $batch->getYear(),
            $periodStart,
            $periodEnd,
            $settings,
            $currency,
            self::FORMAT_NAME,
        );
        $lines[] = $this->buildColumnHeaderLine();

        foreach ($batch->getEntries() as $entry) {
            if ($entry->isOpeningBalance()) {
                continue;
            }
            $lines[] = $this->buildDataLine($entry, $currency);
        }

        return self::UTF8_BOM.implode("\r\n", $lines)."\r\n";
    }

    /**
     * Exports every batch of the given year into one DATEV Buchungsstapel.
     *
     * @param BookingBatch[] $batches batches of the year, in ascending month order
     */
    public function exportYear(int $year, array $batches, AccountingSettings $settings, string $currency = 'EUR'): string
    {
        $periodStart = sprintf('%d0101', $year);
        $periodEnd = sprintf('%d1231', $year);
        $stapelName = sprintf('Buchungsjournal %d', $year);

        $lines = [];
        $lines[] = $this->buildHeaderLine(
            $year,
            $periodStart,
            $periodEnd,
            $settings,
            $currency,
            $stapelName,
        );
        $lines[] = $this->buildColumnHeaderLine();

        foreach ($batches as $batch) {
            foreach ($batch->getEntries() as $entry) {
                $lines[] = $this->buildDataLine($entry, $currency);
            }
        }

        return self::UTF8_BOM.implode("\r\n", $lines)."\r\n";
    }

    /**
     * Validate a batch before export. Returns a list of warning messages.
     *
     * @return string[]
     */
    public function validate(BookingBatch $batch): array
    {
        return $this->validateWithSettings($batch, null);
    }

    public function validateWithSettings(BookingBatch $batch, ?AccountingSettings $settings): array
    {
        $warnings = $this->buildSettingsWarnings($settings);

        foreach ($batch->getEntries() as $entry) {
            if ($entry->isOpeningBalance()) {
                continue;
            }
            $warnings = [...$warnings, ...$this->buildEntryWarnings($entry)];
        }

        return $warnings;
    }

    /**
     * Validate a whole year's batches. Settings are checked once.
     *
     * @param BookingBatch[] $batches
     *
     * @return string[]
     */
    public function validateYear(array $batches, ?AccountingSettings $settings): array
    {
        $warnings = $this->buildSettingsWarnings($settings);

        foreach ($batches as $batch) {
            foreach ($batch->getEntries() as $entry) {
                if ($entry->isOpeningBalance()) {
                    continue;
                }
                $warnings = [...$warnings, ...$this->buildEntryWarnings($entry)];
            }
        }

        return $warnings;
    }

    private function buildHeaderLine(
        int $year,
        string $periodStart,
        string $periodEnd,
        AccountingSettings $settings,
        string $currency,
        string $stapelName,
    ): string {
        $now = new \DateTimeImmutable();
        $fiscalYearStart = sprintf('%d%02d01', $year, $settings->getFiscalYearStart());

        $skrType = $this->resolveSkrType($settings->getChartPreset());

        $fields = array_fill(0, 31, '');

        $fields[0] = '"EXTF"';
        $fields[1] = (string) self::EXTF_VERSION;
        $fields[2] = (string) self::DATA_CATEGORY;
        $fields[3] = '"'.self::FORMAT_NAME.'"';
        $fields[4] = (string) self::FORMAT_VERSION;
        $fields[5] = $now->format('YmdHis').'000';
        $fields[6] = '';
        $fields[7] = '"RE"';
        $fields[8] = '""';
        $fields[9] = '""';
        $fields[10] = $settings->getAdvisorNumber() ?? '';
        $fields[11] = $settings->getClientNumber() ?? '';
        $fields[12] = $fiscalYearStart;
        $fields[13] = (string) $settings->getAccountNumberLength();
        $fields[14] = $periodStart;
        $fields[15] = $periodEnd;
        $fields[16] = '"'.$this->escapeQuotes(mb_substr($stapelName, 0, 30)).'"';
        $fields[17] = null !== $settings->getDictationCode() ? '"'.$settings->getDictationCode().'"' : '""';
        $fields[18] = '1';
        $fields[19] = '0';
        $fields[20] = '0';
        $fields[21] = '"'.$currency.'"';
        $fields[22] = '';
        $fields[23] = '""';
        $fields[24] = '';
        $fields[25] = '';
        $fields[26] = null !== $skrType ? '"'.$skrType.'"' : '""';
        $fields[27] = '';
        $fields[28] = '';
        $fields[29] = '""';
        $fields[30] = '""';

        return implode(';', $fields);
    }

    private function buildColumnHeaderLine(): string
    {
        return implode(';', self::COLUMN_HEADERS);
    }

    private function buildDataLine(BookingEntry $entry, string $currency): string
    {
        $fields = array_fill(0, self::COLUMN_COUNT, '');

        // 1: Umsatz – comma as decimal separator, no thousands separator
        $fields[0] = str_replace('.', ',', number_format((float) $entry->getAmount(), 2, '.', ''));

        // 2: Soll/Haben-Kennzeichen – S=Soll (debit), H=Haben (credit)
        $fields[1] = 'S';

        // 3-6: Fremdwährungsfelder nur belegen, wenn tatsächlich benötigt
        if ('EUR' !== strtoupper($currency)) {
            $fields[2] = strtoupper($currency);
        }

        // 7: Konto (debit account number)
        $fields[6] = null !== $entry->getDebitAccount() ? $entry->getDebitAccount()->getAccountNumber() : '';

        // 8: Gegenkonto (credit account number, without BU key)
        $fields[7] = null !== $entry->getCreditAccount() ? $entry->getCreditAccount()->getAccountNumber() : '';

        // 9: BU-Schlüssel – suppressed when an Automatikkonto is involved;
        // otherwise selected by direction (expense→input/Vorsteuer, revenue→output/Umsatzsteuer).
        $fields[8] = $this->resolveBuKey($entry);

        // 10: Belegdatum – DDMM format
        $fields[9] = $entry->getDate()->format('dm');

        // 11: Belegfeld 1 – invoice number
        $fields[10] = $entry->getInvoiceNumber() ?? '';

        // 13: Skonto – empty

        // 14: Buchungstext – max 60 chars
        $remark = $entry->getRemark() ?? '';
        if (mb_strlen($remark) > 60) {
            $remark = mb_substr($remark, 0, 60);
        }
        $fields[13] = $remark;

        // 43/44: Sachverhalt L+L / Funktionsergänzung L+L – inherited from either account
        // (typically set on §13b reverse-charge expense accounts like SKR03 3123 / SKR04 5923).
        [$sachverhalt, $funktion] = $this->resolveLuL($entry);
        $fields[42] = null !== $sachverhalt ? (string) $sachverhalt : '';
        $fields[43] = null !== $funktion ? (string) $funktion : '';

        return $this->serializeDataLine($fields);
    }

    private function resolveBuKey(BookingEntry $entry): string
    {
        $taxRate = $entry->getTaxRate();
        if (null === $taxRate) {
            return '';
        }

        if ($this->involvesAutoAccount($entry)) {
            return '';
        }

        $debit = $entry->getDebitAccount();
        $credit = $entry->getCreditAccount();

        // Expense posting (debit is an expense account) → Vorsteuer
        if (null !== $debit && AccountingAccount::TYPE_EXPENSE === $debit->getType()) {
            return $taxRate->getDatevInputBuKey() ?? '';
        }

        // Revenue posting (credit is a revenue account) → Umsatzsteuer
        if (null !== $credit && AccountingAccount::TYPE_REVENUE === $credit->getType()) {
            return $taxRate->getDatevOutputBuKey() ?? '';
        }

        // Fallback: prefer output key if set, then input
        return $taxRate->getDatevOutputBuKey() ?? ($taxRate->getDatevInputBuKey() ?? '');
    }

    private function involvesAutoAccount(BookingEntry $entry): bool
    {
        $debit = $entry->getDebitAccount();
        $credit = $entry->getCreditAccount();

        return (null !== $debit && $debit->isAutoAccount())
            || (null !== $credit && $credit->isAutoAccount());
    }

    /**
     * @return array{0: ?int, 1: ?int} sachverhalt, funktionsergaenzung
     */
    private function resolveLuL(BookingEntry $entry): array
    {
        foreach ([$entry->getDebitAccount(), $entry->getCreditAccount()] as $account) {
            if (null === $account) {
                continue;
            }
            if (null !== $account->getDatevSachverhaltLuL() || null !== $account->getDatevFunktionsergaenzungLuL()) {
                return [$account->getDatevSachverhaltLuL(), $account->getDatevFunktionsergaenzungLuL()];
            }
        }

        return [null, null];
    }

    private function resolveSkrType(?string $chartPreset): ?string
    {
        return match ($chartPreset) {
            AccountingSettings::PRESET_SKR03 => '03',
            AccountingSettings::PRESET_SKR04 => '04',
            default => null,
        };
    }

    /**
     * @return string[]
     */
    private function buildSettingsWarnings(?AccountingSettings $settings): array
    {
        $warnings = [];

        if (null === $settings) {
            return $warnings;
        }

        if ('' === trim((string) ($settings->getAdvisorNumber() ?? ''))) {
            $warnings[] = $this->translator->trans('accounting.journal.export.warn.missing_advisor_number');
        }

        if ('' === trim((string) ($settings->getClientNumber() ?? ''))) {
            $warnings[] = $this->translator->trans('accounting.journal.export.warn.missing_client_number');
        }

        return $warnings;
    }

    /**
     * @return string[]
     */
    private function buildEntryWarnings(BookingEntry $entry): array
    {
        $warnings = [];
        $docNr = $entry->getDocumentNumberF();

        if (null === $entry->getDebitAccount()) {
            $warnings[] = $this->translator->trans('accounting.journal.export.warn.no_debit', ['%doc%' => $docNr]);
        }
        if (null === $entry->getCreditAccount()) {
            $warnings[] = $this->translator->trans('accounting.journal.export.warn.no_credit', ['%doc%' => $docNr]);
        }
        $warnings = [...$warnings, ...$this->buildBuKeyWarnings($entry, $docNr)];

        return $warnings;
    }

    /**
     * @return string[]
     */
    private function buildBuKeyWarnings(BookingEntry $entry, string $docNr): array
    {
        $taxRate = $entry->getTaxRate();
        if (null === $taxRate) {
            return [];
        }

        // Zero-rate (tax-free) does not require a BU-Schlüssel.
        if (0.0 === (float) $taxRate->getRate()) {
            return [];
        }

        // Automatikkonten: DATEV derives the tax itself, BU-Schlüssel must be omitted.
        if ($this->involvesAutoAccount($entry)) {
            return [];
        }

        $debit = $entry->getDebitAccount();
        $credit = $entry->getCreditAccount();

        $needsInput = null !== $debit && AccountingAccount::TYPE_EXPENSE === $debit->getType();
        $needsOutput = null !== $credit && AccountingAccount::TYPE_REVENUE === $credit->getType();

        if ($needsInput && null === $taxRate->getDatevInputBuKey()) {
            return [$this->translator->trans('accounting.journal.export.warn.no_input_bukey', ['%doc%' => $docNr, '%taxrate%' => $taxRate->getName()])];
        }

        if ($needsOutput && null === $taxRate->getDatevOutputBuKey()) {
            return [$this->translator->trans('accounting.journal.export.warn.no_output_bukey', ['%doc%' => $docNr, '%taxrate%' => $taxRate->getName()])];
        }

        return [];
    }

    private function serializeDataLine(array $fields): string
    {
        $serialized = [];

        foreach ($fields as $index => $value) {
            $serialized[] = $this->serializeField((string) $value, isset(self::TEXT_COLUMN_INDEXES[$index]));
        }

        return implode(';', $serialized);
    }

    private function serializeField(string $value, bool $quoteAsText): string
    {
        if ($quoteAsText) {
            return '"'.$this->escapeQuotes($value).'"';
        }

        return $value;
    }

    private function escapeQuotes(string $value): string
    {
        return str_replace('"', '""', $value);
    }
}
