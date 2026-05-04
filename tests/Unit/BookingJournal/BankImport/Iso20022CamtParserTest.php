<?php

declare(strict_types=1);

namespace App\Tests\Unit\BookingJournal\BankImport;

use App\Service\BookingJournal\BankImport\Parser\Iso20022CamtParser;
use PHPUnit\Framework\TestCase;

final class Iso20022CamtParserTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__.'/../../../Fixtures/BankImport';

    public function testParsesSparkasseStyleCamt052BookedEntries(): void
    {
        $parser = new Iso20022CamtParser();
        $day1 = $parser->parse(new \SplFileInfo(self::FIXTURE_DIR.'/camt052-booked-day1.xml'), null);
        $day2 = $parser->parse(new \SplFileInfo(self::FIXTURE_DIR.'/camt052-booked-day2.xml'), null);

        self::assertSame('iso20022_camt', $parser->getFormatKey());
        self::assertSame('DE00SPARKASSETEST0001', $day1->sourceIban);
        self::assertCount(2, $day1->lines);
        self::assertCount(1, $day2->lines);

        $income = $day1->lines[0];
        self::assertSame('2026-04-09', $income->bookDate->format('Y-m-d'));
        self::assertSame('518.00', $income->amount);
        self::assertSame('Example Guest GmbH', $income->counterpartyName);
        self::assertSame('DE00GUEST000000000001', $income->counterpartyIban);
        self::assertSame('E2E-IN-001', $income->endToEndId);
        self::assertStringContainsString('Invoice 2026-0101', $income->purpose);

        $outgoing = $day1->lines[1];
        self::assertSame('-29.98', $outgoing->amount);
        self::assertSame('Office Supplies GmbH', $outgoing->counterpartyName);
        self::assertSame('DE00VENDOR0000000001', $outgoing->counterpartyIban);
    }

    public function testSkipsNonBookedCamt052Entries(): void
    {
        $parser = new Iso20022CamtParser();
        $result = $parser->parse(new \SplFileInfo(self::FIXTURE_DIR.'/camt052-pending.xml'), null);

        self::assertCount(1, $result->lines);
        self::assertSame('BOOKED-ONLY', $result->lines[0]->endToEndId);
        self::assertContains('accounting.bank_import.parser.warning.camt_non_booked_skipped', $result->warnings);
    }

    public function testParsesCamt053Statement(): void
    {
        $parser = new Iso20022CamtParser();
        $result = $parser->parse(new \SplFileInfo(self::FIXTURE_DIR.'/camt053-statement.xml'), null);

        self::assertSame('DE00SPARKASSETEST0001', $result->sourceIban);
        self::assertNotNull($result->periodFrom);
        self::assertNotNull($result->periodTo);
        self::assertSame('2026-04-01', $result->periodFrom->format('Y-m-d'));
        self::assertSame('2026-04-30', $result->periodTo->format('Y-m-d'));
        self::assertCount(1, $result->lines);

        $line = $result->lines[0];
        self::assertSame('120.00', $line->amount);
        self::assertSame('Statement Guest GmbH', $line->counterpartyName);
        self::assertSame('DE00STATEMENTGUEST01', $line->counterpartyIban);
        self::assertStringContainsString('Statement invoice 2026-0102', $line->purpose);
    }

    public function testDetectsVersionFromPrefixedNamespaceDeclaration(): void
    {
        $fixture = (string) file_get_contents(self::FIXTURE_DIR.'/camt053-statement.xml');
        $fixture = str_replace(
            '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08">',
            '<Document xmlns:n0="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08">',
            $fixture,
        );

        $tmp = tempnam(sys_get_temp_dir(), 'camt-prefixed-namespace-');
        self::assertIsString($tmp);
        file_put_contents($tmp, $fixture);

        try {
            $parser = new Iso20022CamtParser();
            $result = $parser->parse(new \SplFileInfo($tmp), null);
        } finally {
            @unlink($tmp);
        }

        self::assertSame([], $result->warnings);
        self::assertCount(1, $result->lines);
    }
}
