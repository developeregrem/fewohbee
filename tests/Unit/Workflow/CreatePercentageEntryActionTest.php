<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Entity\AccountingAccount;
use App\Entity\BookingEntry;
use App\Entity\Invoice;
use App\Entity\InvoicePosition;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\TaxRate;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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
        // statement line matches that invoice; a deduction belongs to the day
        // the payment was recorded instead.
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

    public function testReadsTheCommissionFromTheReservationOrigin(): void
    {
        // The percentage is left off the config; it comes from the origin, the
        // same value the guest is shown. The manual field is ignored.
        $captured = null;
        $action = $this->makeAction(gross: 115.20, capture: $captured);

        $config = $this->config(['percent' => '', 'percentSource' => CreatePercentageEntryAction::PERCENT_SOURCE_COMMISSION]);
        $action->execute($config, $this->invoiceWithOrigin(commission: '12', paymentFee: '1.4'), []);

        self::assertSame('13.82', $captured['amount']);
    }

    public function testReadsThePaymentFeeFromTheReservationOrigin(): void
    {
        $captured = null;
        $action = $this->makeAction(gross: 115.20, capture: $captured);

        $config = $this->config(['percent' => '', 'percentSource' => CreatePercentageEntryAction::PERCENT_SOURCE_PAYMENT_FEE]);
        $action->execute($config, $this->invoiceWithOrigin(commission: '12', paymentFee: '1.4'), []);

        self::assertSame('1.61', $captured['amount']);
    }

    public function testSkipsWhenTheOriginSourceHasNoValue(): void
    {
        // A direct booking, or an origin whose fee is not filled in: nothing to
        // book, the same as a manual percentage left blank.
        $action = $this->makeAction(gross: 115.20);

        $config = $this->config(['percent' => '99', 'percentSource' => CreatePercentageEntryAction::PERCENT_SOURCE_COMMISSION]);

        $this->expectException(WorkflowSkippedException::class);
        $action->execute($config, $this->invoiceWithOrigin(commission: null, paymentFee: null), []);
    }

    public function testLeavesTouristTaxOutOfTheBaseByDefaultForNewActions(): void
    {
        // Tourist tax is collected for the municipality, so it is not part of
        // what a portal charges commission on.
        $positions = null;
        $action = $this->makeAction(gross: 115.20, capturePositions: $positions);

        $config = $this->config(['amountBase' => CreatePercentageEntryAction::AMOUNT_BASE_GROSS_WITHOUT_TOURIST_TAX]);
        $action->execute($config, $this->invoiceWithPositions(), []);

        self::assertSame(['Übernachtung', 'Endreinigung'], $this->descriptionsOf($positions));
    }

    public function testKeepsTouristTaxInTheBaseWhenTheFullGrossIsConfigured(): void
    {
        $positions = null;
        $action = $this->makeAction(gross: 115.20, capturePositions: $positions);

        $config = $this->config(['amountBase' => CreatePercentageEntryAction::AMOUNT_BASE_GROSS]);
        $action->execute($config, $this->invoiceWithPositions(), []);

        self::assertSame(['Übernachtung', 'Endreinigung', 'Kurtaxe'], $this->descriptionsOf($positions));
    }

    public function testFallsBackToTheFullGrossForConfigsSavedBeforeTheChoiceExisted(): void
    {
        // Those workflows were set up against that figure; changing what they
        // book from one release to the next would be a silent correction.
        $positions = null;
        $action = $this->makeAction(gross: 115.20, capturePositions: $positions);

        $config = $this->config();
        unset($config['amountBase']);
        $action->execute($config, $this->invoiceWithPositions(), []);

        self::assertSame(['Übernachtung', 'Endreinigung', 'Kurtaxe'], $this->descriptionsOf($positions));
    }

    public function testOffersTheNarrowerBaseAsTheDefaultForNewActions(): void
    {
        $action = $this->makeAction(gross: 100.0);

        $field = null;
        foreach ($action->getConfigSchema() as $entry) {
            if ('amountBase' === $entry['key']) {
                $field = $entry;
            }
        }

        self::assertNotNull($field);
        self::assertSame(CreatePercentageEntryAction::AMOUNT_BASE_GROSS_WITHOUT_TOURIST_TAX, $field['default']);
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
            'amountBase' => CreatePercentageEntryAction::AMOUNT_BASE_GROSS,
            'debitAccountId' => '3',
            'creditAccountId' => '4',
            'taxRateId' => '',
            'remark' => '',
        ], $overrides);
    }

    /**
     * An invoice carrying one tourist-tax position among ordinary ones. Only the
     * position group matters here - which of them end up in the sum is what the
     * base decides, the arithmetic on them is InvoiceService's job.
     */
    private function invoiceWithPositions(): Invoice
    {
        $positions = new ArrayCollection([
            $this->position('Übernachtung', 'apartment'),
            $this->position('Endreinigung', 'misc'),
            $this->position('Kurtaxe', 'tourist_tax'),
        ]);

        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getNumber')->willReturn('17730');
        $invoice->method('getDate')->willReturn(new \DateTime('2026-06-26'));
        $invoice->method('getPositions')->willReturn($positions);

        return $invoice;
    }

    private function position(string $description, string $group): InvoicePosition
    {
        $position = new InvoicePosition();
        $position->setDescription($description);
        $position->setPositionGroup($group);

        return $position;
    }

    /**
     * @param Collection<int, InvoicePosition>|null $positions
     *
     * @return string[]
     */
    private function descriptionsOf(?Collection $positions): array
    {
        self::assertNotNull($positions);

        return array_values(array_map(
            static fn (InvoicePosition $position): string => (string) $position->getDescription(),
            $positions->toArray()
        ));
    }

    private function invoice(string $number = '17730'): Invoice
    {
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getNumber')->willReturn($number);
        $invoice->method('getDate')->willReturn(new \DateTime('2026-06-26'));

        return $invoice;
    }

    private function invoiceWithOrigin(?string $commission, ?string $paymentFee): Invoice
    {
        $origin = new ReservationOrigin();
        $origin->setName('Booking.com');
        $origin->setCommissionPercent($commission);
        $origin->setPaymentFeePercent($paymentFee);

        $reservation = new Reservation();
        $reservation->setReservationOrigin($origin);

        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getNumber')->willReturn('17730');
        $invoice->method('getDate')->willReturn(new \DateTime('2026-06-26'));
        $invoice->method('getReservations')->willReturn(new ArrayCollection([$reservation]));

        return $invoice;
    }

    /**
     * @param array<string, mixed>|null              $capture          receives the arguments the journal was called with
     * @param Collection<int, InvoicePosition>|null  $capturePositions receives the positions the sum was calculated over
     */
    private function makeAction(float $gross, mixed &$capture = null, mixed &$capturePositions = null): CreatePercentageEntryAction
    {
        $invoiceService = $this->createStub(InvoiceService::class);
        $invoiceService->method('calculateSums')->willReturnCallback(
            function ($apartments, $positions, &$vats, &$brutto) use ($gross, &$capturePositions): void {
                $capturePositions = $positions;
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
