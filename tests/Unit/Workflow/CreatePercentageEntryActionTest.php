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
use App\Service\InvoiceSumCalculator;
use App\Service\OriginFeeCalculator;
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

    public function testPrefersTheRateTheReservationWasBookedUnder(): void
    {
        // The portal renegotiated its commission to 18 % since; an invoice for a
        // booking made under 12 % must still be charged 12 %.
        $captured = null;
        $action = $this->makeAction(gross: 115.20, capture: $captured);

        $invoice = $this->invoiceWithOrigin(commission: '18', paymentFee: '2.5', pinnedCommission: '12.00', pinnedPaymentFee: '1.40');

        $config = $this->config(['percent' => '', 'percentSource' => CreatePercentageEntryAction::PERCENT_SOURCE_COMMISSION]);
        $action->execute($config, $invoice, []);

        self::assertSame('13.82', $captured['amount']);
    }

    public function testPrefersThePinnedPaymentFeeAsWell(): void
    {
        $captured = null;
        $action = $this->makeAction(gross: 115.20, capture: $captured);

        $invoice = $this->invoiceWithOrigin(commission: '18', paymentFee: '2.5', pinnedCommission: '12.00', pinnedPaymentFee: '1.40');

        $config = $this->config(['percent' => '', 'percentSource' => CreatePercentageEntryAction::PERCENT_SOURCE_PAYMENT_FEE]);
        $action->execute($config, $invoice, []);

        self::assertSame('1.61', $captured['amount']);
    }

    public function testSkipsWhenTheReservationWasBookedUnderNoFee(): void
    {
        // A pinned "0.00" says the portal charged nothing at the time, which the
        // origin's current rate must not override.
        $action = $this->makeAction(gross: 115.20);

        $invoice = $this->invoiceWithOrigin(commission: '18', paymentFee: '2.5', pinnedCommission: '0.00', pinnedPaymentFee: '0.00');

        $config = $this->config(['percent' => '', 'percentSource' => CreatePercentageEntryAction::PERCENT_SOURCE_COMMISSION]);

        $this->expectException(WorkflowSkippedException::class);
        $action->execute($config, $invoice, []);
    }

    public function testFallsBackToTheOriginForReservationsBookedBeforeRatesWerePinned(): void
    {
        $captured = null;
        $action = $this->makeAction(gross: 115.20, capture: $captured);

        // Nothing pinned, as on every reservation that predates the columns.
        $config = $this->config(['percent' => '', 'percentSource' => CreatePercentageEntryAction::PERCENT_SOURCE_COMMISSION]);
        $action->execute($config, $this->invoiceWithOrigin(commission: '12', paymentFee: '1.4'), []);

        self::assertSame('13.82', $captured['amount']);
    }

    public function testSkipsWhenTheInvoiceMixesTwoPortals(): void
    {
        // One entry is booked for the whole invoice, and there is no attribution
        // of invoice lines to reservations to split it along. Charging either
        // portal's rate on the full amount would be wrong without saying so.
        $action = $this->makeAction(gross: 115.20);

        $invoice = $this->invoiceWithReservations(
            $this->reservation(commission: '12', paymentFee: '1.4'),
            $this->reservation(commission: '18', paymentFee: '2.5'),
        );

        $config = $this->config(['percent' => '', 'percentSource' => CreatePercentageEntryAction::PERCENT_SOURCE_COMMISSION]);

        $this->expectException(WorkflowSkippedException::class);
        $action->execute($config, $invoice, []);
    }

    public function testSkipsWhenAPortalBookingSharesTheInvoiceWithADirectOne(): void
    {
        // The direct booking's share carries no commission, so the portal's rate
        // does not hold for the invoice as a whole.
        $action = $this->makeAction(gross: 115.20);

        $invoice = $this->invoiceWithReservations(
            $this->reservation(commission: '12', paymentFee: '1.4'),
            new Reservation(),
        );

        $config = $this->config(['percent' => '', 'percentSource' => CreatePercentageEntryAction::PERCENT_SOURCE_COMMISSION]);

        $this->expectException(WorkflowSkippedException::class);
        $action->execute($config, $invoice, []);
    }

    public function testSkipsWhenTwoBookingsFromOnePortalCarryDifferentPinnedRates(): void
    {
        // Same portal, but the contract changed between the two bookings.
        $action = $this->makeAction(gross: 115.20);

        $origin = $this->origin(commission: '18', paymentFee: '2.5');

        $early = new Reservation();
        $early->setReservationOrigin($origin);
        $early->setCommissionPercent('12.00');

        $late = new Reservation();
        $late->setReservationOrigin($origin);
        $late->setCommissionPercent('18.00');

        $config = $this->config(['percent' => '', 'percentSource' => CreatePercentageEntryAction::PERCENT_SOURCE_COMMISSION]);

        $this->expectException(WorkflowSkippedException::class);
        $action->execute($config, $this->invoiceWithReservations($early, $late), []);
    }

    public function testBooksWhenSeveralReservationsAgreeOnTheRate(): void
    {
        $captured = null;
        $action = $this->makeAction(gross: 115.20, capture: $captured);

        $invoice = $this->invoiceWithReservations(
            $this->reservation(commission: '12', paymentFee: '1.4'),
            $this->reservation(commission: '12.00', paymentFee: '1.40'),
        );

        $config = $this->config(['percent' => '', 'percentSource' => CreatePercentageEntryAction::PERCENT_SOURCE_COMMISSION]);
        $action->execute($config, $invoice, []);

        self::assertSame('13.82', $captured['amount']);
    }

    public function testDoesNotCheckTheRatesWhenThePercentageIsTypedIn(): void
    {
        // A manual percentage says what to book regardless of where the bookings
        // came from; the origins are none of its business.
        $captured = null;
        $action = $this->makeAction(gross: 115.20, capture: $captured);

        $invoice = $this->invoiceWithReservations(
            $this->reservation(commission: '12', paymentFee: '1.4'),
            $this->reservation(commission: '18', paymentFee: '2.5'),
        );

        $action->execute($this->config(['percent' => '12']), $invoice, []);

        self::assertSame('13.82', $captured['amount']);
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

    public function testTreatsAConfigWithoutTheKeyLikeANewOne(): void
    {
        // The field ships with the action, so only a workflow configured while
        // this was still being built can lack it - no reason to keep a second
        // behaviour around for those.
        $positions = null;
        $action = $this->makeAction(gross: 115.20, capturePositions: $positions);

        $config = $this->config();
        unset($config['amountBase']);
        $action->execute($config, $this->invoiceWithPositions(), []);

        self::assertSame(['Übernachtung', 'Endreinigung'], $this->descriptionsOf($positions));
    }

    public function testMarksTheEntryAsComingFromAWorkflow(): void
    {
        // createEntryFromStatement() serves the bank import and hands back
        // something marked manual; a deduction nobody typed in must not stay
        // that way, or the journal cannot tell the two apart.
        $capture = null;
        $action = $this->makeAction(gross: 100.0, capture: $capture);

        $action->execute($this->config(), $this->invoiceWithPositions(), []);

        self::assertSame(BookingEntry::SOURCE_WORKFLOW, $capture['entry']->getSourceType());
    }

    public function testOffersTheNarrowerBaseAsTheDefaultForNewActions(): void
    {
        $action = $this->makeAction(gross: 100.0);

        self::assertSame(
            CreatePercentageEntryAction::AMOUNT_BASE_GROSS_WITHOUT_TOURIST_TAX,
            $this->amountBaseField($action)['default'] ?? null
        );
    }

    /** @return array<string, mixed>|null */
    private function amountBaseField(CreatePercentageEntryAction $action): ?array
    {
        foreach ($action->getConfigSchema() as $field) {
            if ('amountBase' === $field['key']) {
                return $field;
            }
        }

        return null;
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

    private function invoiceWithOrigin(
        ?string $commission,
        ?string $paymentFee,
        ?string $pinnedCommission = null,
        ?string $pinnedPaymentFee = null,
    ): Invoice {
        $reservation = $this->reservation($commission, $paymentFee);
        // Overwritten after the assignment, which pins the origin's current rates -
        // here the reservation is meant to carry what applied when it was booked.
        $reservation->setCommissionPercent($pinnedCommission);
        $reservation->setPaymentFeePercent($pinnedPaymentFee);

        return $this->invoiceWithReservations($reservation);
    }

    private function invoiceWithReservations(Reservation ...$reservations): Invoice
    {
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getNumber')->willReturn('17730');
        $invoice->method('getDate')->willReturn(new \DateTime('2026-06-26'));
        $invoice->method('getReservations')->willReturn(new ArrayCollection($reservations));

        return $invoice;
    }

    /** A reservation booked through a portal, carrying that portal's current rates. */
    private function reservation(?string $commission, ?string $paymentFee): Reservation
    {
        $reservation = new Reservation();
        $reservation->setReservationOrigin($this->origin($commission, $paymentFee));

        return $reservation;
    }

    private function origin(?string $commission, ?string $paymentFee): ReservationOrigin
    {
        $origin = new ReservationOrigin();
        $origin->setName('Booking.com');
        $origin->setCommissionPercent($commission);
        $origin->setPaymentFeePercent($paymentFee);

        return $origin;
    }

    /**
     * @param array<string, mixed>|null              $capture          receives the arguments the journal was called with
     * @param Collection<int, InvoicePosition>|null  $capturePositions receives the positions the sum was calculated over
     */
    private function makeAction(float $gross, mixed &$capture = null, mixed &$capturePositions = null): CreatePercentageEntryAction
    {
        // Stubbed at the sum, so the invoice's own arithmetic stays out of it and
        // what is left to check is which positions went into the base. The
        // calculator on top of it is real - picking the base apart is its job,
        // and stubbing it would leave the action tested against nothing.
        $sums = $this->createStub(InvoiceSumCalculator::class);
        $sums->method('grossTotal')->willReturnCallback(
            function ($apartments, $positions) use ($gross, &$capturePositions): float {
                $capturePositions = $positions;

                return $gross;
            }
        );
        $originFees = new OriginFeeCalculator($sums);

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

                // A real entry, not a stub: the action sets properties on what
                // it gets back, and a stub would swallow them unseen.
                $entry = new BookingEntry();
                $entry->setInvoiceNumber($invoiceNumber);
                // What the real createEntryFromStatement() leaves behind - the
                // action is expected to correct it.
                $entry->setSourceType(BookingEntry::SOURCE_MANUAL);
                $capture['entry'] = $entry;

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

        return new CreatePercentageEntryAction($journal, $accountRepo, $taxRateRepo, $originFees, $translator);
    }
}
