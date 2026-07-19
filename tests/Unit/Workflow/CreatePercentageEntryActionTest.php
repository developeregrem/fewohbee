<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Entity\AccountingAccount;
use App\Entity\BookingEntry;
use App\Entity\Invoice;
use App\Entity\TaxRate;
use App\Repository\AccountingAccountRepository;
use App\Repository\TaxRateRepository;
use App\Service\BookingJournal\BookingJournalService;
use App\Service\InvoiceService;
use App\Workflow\Action\CreatePercentageEntryAction;
use App\Workflow\WorkflowSkippedException;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The action turns a percentage into money, so the arithmetic and the rounding
 * are what matter: an entry that is a cent off has to be corrected by hand
 * every time, which defeats the point of automating it.
 */
final class CreatePercentageEntryActionTest extends TestCase
{
    public function testBooksTheConfiguredPercentageOfTheGrossTotal(): void
    {
        $captured = null;
        $action = $this->makeAction(gross: 115.20, capture: $captured);

        $action->execute($this->config(['percent' => '12']), $this->invoice(), []);

        // 115.20 * 12 % = 13.824, commercially rounded.
        self::assertSame('13.82', $captured['amount']);
    }

    public function testRoundsToTwoDecimals(): void
    {
        $captured = null;
        $action = $this->makeAction(gross: 115.20, capture: $captured);

        $action->execute($this->config(['percent' => '1.4']), $this->invoice(), []);

        // 115.20 * 1.4 % = 1.6128
        self::assertSame('1.61', $captured['amount']);
    }

    public function testAcceptsACommaAsDecimalSeparator(): void
    {
        // The field is free text and German keyboards produce commas.
        $captured = null;
        $action = $this->makeAction(gross: 115.20, capture: $captured);

        $action->execute($this->config(['percent' => '1,4']), $this->invoice(), []);

        self::assertSame('1.61', $captured['amount']);
    }

    public function testPutsTheInvoiceNumberIntoTheRemark(): void
    {
        $captured = null;
        $action = $this->makeAction(gross: 100.0, capture: $captured);

        $action->execute($this->config(['percent' => '10', 'remark' => 'Kommission %number%']), $this->invoice('17730'), []);

        self::assertSame('Kommission 17730', $captured['remark']);
    }

    public function testLeavesTheDocumentNumberEmptyForLater(): void
    {
        // The reference that belongs in that field is the supplier's invoice
        // for the deduction, which does not exist yet - the remark carries the
        // link to our own invoice instead.
        $captured = null;
        $action = $this->makeAction(gross: 100.0, capture: $captured);

        $action->execute($this->config(['percent' => '10']), $this->invoice('17730'), []);

        self::assertNull($captured['invoiceNumber']);
    }

    public function testLeavesTheInvoiceIdUnsetSoThePayoutDoesNotRedateIt(): void
    {
        // The bank import re-dates every entry carrying an invoiceId once a
        // statement line matches that invoice; a deduction belongs to the
        // invoice date instead.
        $captured = null;
        $action = $this->makeAction(gross: 100.0, capture: $captured);

        $action->execute($this->config(['percent' => '10']), $this->invoice(), []);

        self::assertNull($captured['invoiceId']);
    }

    public function testSkipsWithoutAPercentage(): void
    {
        $action = $this->makeAction(gross: 100.0);

        $this->expectException(WorkflowSkippedException::class);
        $action->execute($this->config(['percent' => '']), $this->invoice(), []);
    }

    public function testSkipsWhenTheInvoiceHasNoAmount(): void
    {
        $action = $this->makeAction(gross: 0.0);

        $this->expectException(WorkflowSkippedException::class);
        $action->execute($this->config(['percent' => '12']), $this->invoice(), []);
    }

    public function testSkipsForAnyOtherEntity(): void
    {
        $action = $this->makeAction(gross: 100.0);

        $this->expectException(WorkflowSkippedException::class);
        $action->execute($this->config(['percent' => '12']), new \stdClass(), []);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'percent' => '12',
            'debitAccountId' => '3',
            'creditAccountId' => '4',
            'taxRateId' => '',
            'remark' => '',
        ], $overrides);
    }

    private function invoice(string $number = '17730'): Invoice
    {
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getNumber')->willReturn($number);
        $invoice->method('getDate')->willReturn(new \DateTime('2026-06-26'));

        return $invoice;
    }

    /** @param array<string, mixed>|null $capture receives the arguments the journal was called with */
    private function makeAction(float $gross, mixed &$capture = null): CreatePercentageEntryAction
    {
        $invoiceService = $this->createStub(InvoiceService::class);
        $invoiceService->method('calculateSums')->willReturnCallback(
            function ($apartments, $positions, &$vats, &$brutto) use ($gross): void {
                $brutto = $gross;
            }
        );

        $journal = $this->createStub(BookingJournalService::class);
        $journal->method('createEntryFromStatement')->willReturnCallback(
            function ($date, $amount, $debit, $credit, $remark, $invoiceNumber = null, $invoiceId = null, $splitGroup = null, $taxRate = null) use (&$capture) {
                $capture = [
                    'date' => $date,
                    'amount' => $amount,
                    'remark' => $remark,
                    'invoiceNumber' => $invoiceNumber,
                    'invoiceId' => $invoiceId,
                    'taxRate' => $taxRate,
                ];

                $entry = $this->createStub(BookingEntry::class);
                $entry->method('getInvoiceNumber')->willReturn($invoiceNumber);

                return $entry;
            }
        );

        $accountRepo = $this->createStub(AccountingAccountRepository::class);
        $accountRepo->method('find')->willReturn($this->createStub(AccountingAccount::class));

        $taxRateRepo = $this->createStub(TaxRateRepository::class);
        $taxRateRepo->method('findAllOrdered')->willReturn([]);
        $taxRateRepo->method('find')->willReturn($this->createStub(TaxRate::class));

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('ok');

        return new CreatePercentageEntryAction($journal, $accountRepo, $taxRateRepo, $invoiceService, $translator);
    }
}
