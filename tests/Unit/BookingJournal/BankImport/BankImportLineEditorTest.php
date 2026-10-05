<?php

declare(strict_types=1);

namespace App\Tests\Unit\BookingJournal\BankImport;

use App\Dto\BookingJournal\BankImport\ImportState;
use App\Service\BookingJournal\BankImport\BankImportLineEditor;
use PHPUnit\Framework\TestCase;

final class BankImportLineEditorTest extends TestCase
{
    public function testAssigningBothAccountsMakesALineReady(): void
    {
        $line = ['amount' => '-12.30', 'status' => ImportState::LINE_STATUS_PENDING];

        BankImportLineEditor::setField($line, 'debitAccountId', '7');
        self::assertSame(ImportState::LINE_STATUS_PENDING, $line['status']);
        BankImportLineEditor::setField($line, 'creditAccountId', 3);

        self::assertSame(7, $line['userDebitAccountId']);
        self::assertSame(3, $line['userCreditAccountId']);
        self::assertSame(ImportState::LINE_STATUS_READY, $line['status']);
    }

    public function testCleansTextsAndIds(): void
    {
        $line = [];

        BankImportLineEditor::setField($line, 'remark', '  '.str_repeat('x', 300).'  ');
        BankImportLineEditor::setField($line, 'invoiceNumber', '   ');
        BankImportLineEditor::setField($line, 'taxRateId', '-4');
        BankImportLineEditor::setField($line, 'isIgnored', '1');

        self::assertSame(255, mb_strlen((string) $line['userRemark']));
        self::assertNull($line['userInvoiceNumber']);
        self::assertNull($line['userTaxRateId']);
        self::assertSame(ImportState::LINE_STATUS_IGNORED, $line['status']);
    }

    public function testRejectsUnknownFields(): void
    {
        $line = [];

        $this->expectException(\InvalidArgumentException::class);
        BankImportLineEditor::setField($line, 'amount', '1000.00');
    }

    public function testDuplicatesAreOnlyEditableWhenForced(): void
    {
        self::assertTrue(BankImportLineEditor::isEditable([]));
        self::assertFalse(BankImportLineEditor::isEditable(['isDuplicate' => true]));
        self::assertTrue(BankImportLineEditor::isEditable(['isDuplicate' => true, 'forceImportDuplicate' => true]));
    }
}
