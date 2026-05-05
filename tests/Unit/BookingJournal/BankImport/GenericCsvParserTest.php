<?php

declare(strict_types=1);

namespace App\Tests\Unit\BookingJournal\BankImport;

use App\Dto\BookingJournal\BankImport\StatementLineDto;
use App\Entity\BankCsvProfile;
use App\Service\BookingJournal\BankImport\Parser\GenericCsvParser;
use PHPUnit\Framework\TestCase;

final class GenericCsvParserTest extends TestCase
{
    private const EXAMPLE_CSV = __DIR__.'/../../../Fixtures/BankImport/dkb-girokonto-anonymized.csv';
    private const SPARKASSE_CSV = __DIR__.'/../../../Fixtures/BankImport/sparkasse-girokonto-anonymized.csv';

    public function testParsesDkbExampleFully(): void
    {
        if (!is_file(self::EXAMPLE_CSV)) {
            self::markTestSkipped('Beispiel-CSV nicht vorhanden.');
        }

        $profile = $this->createDkbProfile();
        $parser = new GenericCsvParser();
        $result = $parser->parse(new \SplFileInfo(self::EXAMPLE_CSV), $profile);

        self::assertSame('csv_generic', $parser->getFormatKey());

        self::assertCount(7, $result->lines, sprintf(
            'Erwartet 7 Datenzeilen, bekam %d. Warnungen: %s',
            count($result->lines),
            implode(' / ', $result->warnings),
        ));
        self::assertSame([], $result->warnings);

        // IBAN aus dem Vorspann.
        self::assertSame('DE00DKBTESTKONTO0001', $result->sourceIban);

        // Zeitraum aus dem Vorspann.
        self::assertNotNull($result->periodFrom);
        self::assertNotNull($result->periodTo);
        self::assertSame('2026-03-01', $result->periodFrom->format('Y-m-d'));
        self::assertSame('2026-03-31', $result->periodTo->format('Y-m-d'));

        $first = $result->lines[0];
        self::assertInstanceOf(StatementLineDto::class, $first);
        self::assertSame('2026-03-31', $first->bookDate->format('Y-m-d'));
        self::assertSame('2026-03-31', $first->valueDate->format('Y-m-d'));
        self::assertSame('-41.98', $first->amount);
        self::assertSame('MUSTERHANDEL XYZ', $first->counterpartyName);
        self::assertSame('DKB-CP-001', $first->counterpartyIban);
        self::assertStringContainsString('VISA Debitkartenumsatz', $first->purpose);
        self::assertSame('DKB-2026-000001', $first->endToEndId);
    }

    public function testParsesIncomingAmountWithThousandsSeparator(): void
    {
        if (!is_file(self::EXAMPLE_CSV)) {
            self::markTestSkipped('Beispiel-CSV nicht vorhanden.');
        }

        $profile = $this->createDkbProfile();
        $parser = new GenericCsvParser();
        $result = $parser->parse(new \SplFileInfo(self::EXAMPLE_CSV), $profile);

        // Lohn/Gehalt-Zeile: "8.837,21" → 8837.21 mit positivem Vorzeichen.
        $income = $this->findLineByPurpose($result->lines, 'Lohn/Gehalt');
        self::assertNotNull($income);
        self::assertSame('8837.21', $income->amount);
        self::assertTrue($income->isIncoming());
    }

    public function testParsesNegativeThousandsAmount(): void
    {
        if (!is_file(self::EXAMPLE_CSV)) {
            self::markTestSkipped('Beispiel-CSV nicht vorhanden.');
        }

        $profile = $this->createDkbProfile();
        $parser = new GenericCsvParser();
        $result = $parser->parse(new \SplFileInfo(self::EXAMPLE_CSV), $profile);

        // Invoice-like reference row: "-318,50" → -318.50.
        $invoice = $this->findLineByPurpose($result->lines, '2026-826');
        self::assertNotNull($invoice);
        self::assertSame('-318.50', $invoice->amount);
        self::assertFalse($invoice->isIncoming());
    }

    public function testParsesSparkasseExampleFully(): void
    {
        if (!is_file(self::SPARKASSE_CSV)) {
            self::markTestSkipped('Sparkassen-Beispiel-CSV nicht vorhanden.');
        }

        $profile = $this->createSparkasseProfile();
        $parser = new GenericCsvParser();
        $result = $parser->parse(new \SplFileInfo(self::SPARKASSE_CSV), $profile);

        self::assertSame('csv_generic', $parser->getFormatKey());
        self::assertCount(4, $result->lines, sprintf(
            'Erwartet 4 Sparkassen-Datenzeilen, bekam %d. Warnungen: %s',
            count($result->lines),
            implode(' / ', $result->warnings),
        ));
        self::assertSame([], $result->warnings);

        self::assertSame('DE00SPKTESTKONTO0001', $result->sourceIban);
        self::assertNull($result->periodFrom);
        self::assertNull($result->periodTo);

        $first = $result->lines[0];
        self::assertSame('2026-04-30', $first->bookDate->format('Y-m-d'));
        self::assertSame('2026-05-01', $first->valueDate->format('Y-m-d'));
        self::assertSame('-14.60', $first->amount);
        self::assertSame('TEST SPARKASSE', $first->counterpartyName);
        self::assertSame('SPK-CP-FEE', $first->counterpartyIban);
        self::assertSame('SPK-E2E-0001', $first->endToEndId);
        self::assertStringContainsString('Entgeltabrechnung', $first->purpose);

        $income = $this->findLineByPurpose($result->lines, '2026-826');
        self::assertNotNull($income);
        self::assertSame('1234.56', $income->amount);
        self::assertTrue($income->isIncoming());
        self::assertSame('BEISPIELKUNDE AG', $income->counterpartyName);

        $rent = $this->findLineByPurpose($result->lines, 'Miete 1790');
        self::assertNotNull($rent);
        self::assertSame('-2260.00', $rent->amount);
        self::assertFalse($rent->isIncoming());

        $fingerprints = array_map(static fn (StatementLineDto $line): string => $line->fingerprint(), $result->lines);
        self::assertCount(count($fingerprints), array_unique($fingerprints));
    }

    public function testFingerprintIsDeterministicAndDistinguishesAmounts(): void
    {
        $base = new StatementLineDto(
            bookDate: new \DateTimeImmutable('2026-03-15'),
            valueDate: new \DateTimeImmutable('2026-03-15'),
            amount: '-12.34',
            counterpartyName: 'ACME GmbH',
            counterpartyIban: 'CP-001',
            purpose: 'Rechnung 2026-0007',
            endToEndId: 'X-1',
        );

        $sameAgain = new StatementLineDto(
            bookDate: new \DateTimeImmutable('2026-03-15'),
            valueDate: new \DateTimeImmutable('2026-03-15'),
            amount: '-12.34',
            counterpartyName: 'IRRELEVANT — name not part of fingerprint',
            counterpartyIban: 'CP-001',
            purpose: '  Rechnung 2026-0007  ',
            endToEndId: 'X-1',
        );

        $differentAmount = new StatementLineDto(
            bookDate: new \DateTimeImmutable('2026-03-15'),
            valueDate: new \DateTimeImmutable('2026-03-15'),
            amount: '-12.35',
            counterpartyName: 'ACME GmbH',
            counterpartyIban: 'CP-001',
            purpose: 'Rechnung 2026-0007',
            endToEndId: 'X-1',
        );

        self::assertSame($base->fingerprint(), $sameAgain->fingerprint(),
            'Whitespace und Gegenpartei-Name dürfen den Fingerabdruck nicht beeinflussen.');
        self::assertNotSame($base->fingerprint(), $differentAmount->fingerprint(),
            'Unterschiedliche Beträge müssen unterschiedliche Fingerabdrücke ergeben.');
    }

    public function testSeparateColumnsProfile(): void
    {
        $csv = <<<CSV
Datum,Soll,Haben,Zweck
15.03.2026,10.50,,Ausgabe
16.03.2026,,250.00,Eingang
CSV;

        $tmp = tmpfile();
        $meta = stream_get_meta_data($tmp);
        fwrite($tmp, $csv);

        $profile = (new BankCsvProfile())
            ->setName('Sep')
            ->setDelimiter(',')
            ->setEnclosure('"')
            ->setEncoding('UTF-8')
            ->setHeaderSkip(0)
            ->setHasHeaderRow(true)
            ->setColumnMap([
                'bookDate'     => 0,
                'amountDebit'  => 1,
                'amountCredit' => 2,
                'purpose'      => 3,
            ])
            ->setDateFormat('d.m.Y')
            ->setAmountDecimalSeparator('.')
            ->setAmountThousandsSeparator(null)
            ->setDirectionMode(BankCsvProfile::DIRECTION_SEPARATE_COLUMNS);

        $parser = new GenericCsvParser();
        $result = $parser->parse(new \SplFileInfo($meta['uri']), $profile);

        self::assertCount(2, $result->lines);
        self::assertSame('-10.50', $result->lines[0]->amount);
        self::assertSame('250.00', $result->lines[1]->amount);
    }

    public function testTwoDigitBankYearWithFullYearProfileIsNormalizedToCurrentCentury(): void
    {
        $csv = <<<CSV
Datum;Betrag;Zweck
02.03.26;-10,00;Miete
CSV;

        $tmp = tmpfile();
        $meta = stream_get_meta_data($tmp);
        fwrite($tmp, $csv);

        $profile = (new BankCsvProfile())
            ->setName('Short year')
            ->setDelimiter(';')
            ->setEnclosure('"')
            ->setEncoding('UTF-8')
            ->setHeaderSkip(0)
            ->setHasHeaderRow(true)
            ->setColumnMap([
                'bookDate' => 0,
                'amount'   => 1,
                'purpose'  => 2,
            ])
            ->setDateFormat('d.m.Y')
            ->setAmountDecimalSeparator(',')
            ->setAmountThousandsSeparator(null)
            ->setDirectionMode(BankCsvProfile::DIRECTION_SIGNED);

        $result = (new GenericCsvParser())->parse(new \SplFileInfo($meta['uri']), $profile);

        self::assertCount(1, $result->lines);
        self::assertSame('2026-03-02', $result->lines[0]->bookDate->format('Y-m-d'));
    }

    public function testTwoDigitBankYearIsAlwaysInterpretedAsTwoThousandYear(): void
    {
        $csv = <<<CSV
Datum;Betrag;Zweck
02.03.99;-10,00;Miete
CSV;

        $tmp = tmpfile();
        $meta = stream_get_meta_data($tmp);
        fwrite($tmp, $csv);

        $profile = (new BankCsvProfile())
            ->setName('Short year')
            ->setDelimiter(';')
            ->setEnclosure('"')
            ->setEncoding('UTF-8')
            ->setHeaderSkip(0)
            ->setHasHeaderRow(true)
            ->setColumnMap([
                'bookDate' => 0,
                'amount'   => 1,
                'purpose'  => 2,
            ])
            ->setDateFormat('d.m.Y')
            ->setAmountDecimalSeparator(',')
            ->setAmountThousandsSeparator(null)
            ->setDirectionMode(BankCsvProfile::DIRECTION_SIGNED);

        $result = (new GenericCsvParser())->parse(new \SplFileInfo($meta['uri']), $profile);

        self::assertCount(1, $result->lines);
        self::assertSame('2099-03-02', $result->lines[0]->bookDate->format('Y-m-d'));
    }

    private function createDkbProfile(): BankCsvProfile
    {
        return (new BankCsvProfile())
            ->setName('DKB Girokonto (Test)')
            ->setDelimiter(';')
            ->setEnclosure('"')
            ->setEncoding('UTF-8')
            ->setHeaderSkip(4)
            ->setHasHeaderRow(true)
            ->setColumnMap([
                'bookDate'         => 0,
                'valueDate'        => 1,
                'counterpartyName' => 4,
                'purpose'          => 5,
                'counterpartyIban' => 7,
                'amount'           => 8,
                'endToEndId'       => 11,
            ])
            ->setDateFormat('d.m.y')
            ->setAmountDecimalSeparator(',')
            ->setAmountThousandsSeparator('.')
            ->setDirectionMode(BankCsvProfile::DIRECTION_SIGNED)
            ->setIbanSourceLine(0)
            ->setPeriodSourceLine(1);
    }

    private function createSparkasseProfile(): BankCsvProfile
    {
        return (new BankCsvProfile())
            ->setName('Sparkasse Girokonto (Test)')
            ->setDelimiter(';')
            ->setEnclosure('"')
            ->setEncoding('UTF-8')
            ->setHeaderSkip(0)
            ->setHasHeaderRow(true)
            ->setColumnMap([
                'bookDate'         => 1,
                'valueDate'        => 2,
                'counterpartyName' => 11,
                'purpose'          => 4,
                'counterpartyIban' => 12,
                'amount'           => 14,
                'creditorId'       => 5,
                'mandateReference' => 6,
                'endToEndId'       => 7,
            ])
            ->setDateFormat('d.m.y')
            ->setAmountDecimalSeparator(',')
            ->setAmountThousandsSeparator('.')
            ->setDirectionMode(BankCsvProfile::DIRECTION_SIGNED)
            ->setIbanSourceLine(1);
    }

    /**
     * @param list<StatementLineDto> $lines
     */
    private function findLineByPurpose(array $lines, string $needle): ?StatementLineDto
    {
        foreach ($lines as $line) {
            if (str_contains($line->purpose, $needle)) {
                return $line;
            }
        }

        return null;
    }
}
