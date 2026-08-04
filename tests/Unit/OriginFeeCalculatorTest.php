<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dto\OriginFeeBreakdown;
use App\Entity\Enum\PaymentCollection;
use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\InvoicePosition;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Service\InvoiceSumCalculator;
use App\Service\OriginFeeCalculator;
use PHPUnit\Framework\TestCase;

/**
 * What a portal costs the house for one booking. The figures end up in two
 * places that must not disagree - the surcharge an invoice shows the guest and
 * the deduction the journal books - so they are worked out here once.
 *
 * Grown out of InvoiceServiceGuestSurchargeTest, whose cases are all still
 * here: the calculation moved out of InvoiceService, the answers did not.
 */
final class OriginFeeCalculatorTest extends TestCase
{
    public function testSplitsCommissionAndPaymentFeeAndSumsThem(): void
    {
        // 12 % and 1.4 % of 115.20 gross = 13.82 and 1.61, matching the two
        // deductions booked for it. Their sum is left to the template.
        $invoice = $this->invoiceWithOrigin('Booking.com', '12.00', '1.40');

        $fees = $this->calculate($invoice, gross: 115.20);

        self::assertSame('Booking.com', $fees->originName);
        self::assertSame(13.82, $fees->commission->amount);
        self::assertSame(1.61, $fees->paymentFee->amount);
    }

    public function testCountsAnOriginWithOnlyOneOfTheTwoPercentages(): void
    {
        $invoice = $this->invoiceWithOrigin('Fewo-direkt', '15.00', null);

        $fees = $this->calculate($invoice, gross: 200.0);

        self::assertSame(30.0, $fees->commission->amount);
        self::assertSame(0.0, $fees->paymentFee->amount);
    }

    public function testReturnsZeroWhenTheOriginHasNeitherPercentage(): void
    {
        $invoice = $this->invoiceWithOrigin('Direktbuchung', null, null);

        $fees = $this->calculate($invoice, gross: 115.20);

        self::assertNull($fees->originName);
        self::assertSame(0.0, $fees->commission->amount);
        self::assertSame(0.0, $fees->paymentFee->amount);
    }

    public function testReturnsZeroWhenNoReservationCarriesAnOrigin(): void
    {
        $invoice = $this->invoice();
        $invoice->addReservation(new Reservation());

        $fees = $this->calculate($invoice, gross: 200.0);

        self::assertNull($fees->originName);
        self::assertSame(0.0, $fees->commission->amount);
        self::assertSame(0.0, $fees->paymentFee->amount);
    }

    public function testSkipsOriginsWithoutPercentagesAndTakesTheFirstThatHasOne(): void
    {
        $invoice = $this->invoice();
        $invoice->addReservation($this->reservationWithOrigin('Direktbuchung', null, null));
        $invoice->addReservation($this->reservationWithOrigin('Booking.com', '12.00', '1.40'));

        $fees = $this->calculate($invoice, gross: 100.0);

        self::assertSame('Booking.com', $fees->originName);
        self::assertSame(12.0, $fees->commission->amount);
        self::assertSame(1.4, $fees->paymentFee->amount);
    }

    public function testShowsTheRatesTheReservationWasBookedUnder(): void
    {
        // The portal has since raised its commission to 18 %. Showing that to the
        // guest would name a figure the journal never booked - the deduction goes
        // by the 12 % the booking was made under.
        $reservation = $this->reservationWithOrigin('Booking.com', '18.00', '2.50');
        $reservation->setCommissionPercent('12.00');
        $reservation->setPaymentFeePercent('1.40');

        $invoice = $this->invoice();
        $invoice->addReservation($reservation);

        $fees = $this->calculate($invoice, gross: 115.20);

        self::assertSame(13.82, $fees->commission->amount);
        self::assertSame(1.61, $fees->paymentFee->amount);
    }

    public function testFallsBackToTheOriginWhenTheReservationHasNoRatesPinned(): void
    {
        // Booked before the rates were pinned, or under an origin that carried
        // none at the time: the origin is all there is to go on.
        $reservation = $this->reservationWithOrigin('Booking.com', '12.00', '1.40');
        $reservation->setCommissionPercent(null);
        $reservation->setPaymentFeePercent(null);

        $invoice = $this->invoice();
        $invoice->addReservation($reservation);

        $fees = $this->calculate($invoice, gross: 115.20);

        self::assertSame(13.82, $fees->commission->amount);
        self::assertSame(1.61, $fees->paymentFee->amount);
    }

    // ── the two bases ────────────────────────────────────────────────

    public function testCommissionLeavesOutWhatCarriesNoneWhileThePaymentFeeKeepsIt(): void
    {
        // 100.00 room plus a 15.00 tourist tax the portal collected: exempt from
        // commission, but the portal handled the money, so its payment fee is
        // charged on all of it.
        $invoice = $this->invoiceCollectedBy(PaymentCollection::PORTAL);
        $invoice->addPosition($this->position('Übernachtung', 100.00));
        $invoice->addPosition($this->position('Kurtaxe', 15.00, commissionable: false));

        $fees = $this->calculate($invoice);

        self::assertSame(100.00, $fees->commission->base);
        self::assertSame(115.00, $fees->paymentFee->base);
        self::assertSame(12.00, $fees->commission->amount);
        self::assertSame(1.61, $fees->paymentFee->amount);
    }

    public function testWhatTheHouseSellsOnSiteCarriesNeitherFee(): void
    {
        // A breakfast ordered at the counter on a portal booking: the portal
        // neither brokered nor processed it, so it drops out of both bases -
        // the case a rule about tourist tax alone could never cover.
        $invoice = $this->invoiceCollectedBy(PaymentCollection::PORTAL);
        $invoice->addPosition($this->position('Übernachtung', 100.00));
        $invoice->addPosition($this->position('Frühstück vor Ort', 20.00, brokered: false, commissionable: false));

        $fees = $this->calculate($invoice);

        self::assertSame(100.00, $fees->commission->base);
        self::assertSame(100.00, $fees->paymentFee->base);
    }

    public function testTheBasesAreEqualWhereEverythingIsBrokeredAndCommissionable(): void
    {
        // The common case, and the reason the split goes unnoticed by most
        // houses: with nothing exempt there is nothing to leave out.
        $invoice = $this->invoiceCollectedBy(PaymentCollection::PORTAL);
        $invoice->addPosition($this->position('Übernachtung', 115.20));

        $fees = $this->calculate($invoice);

        self::assertSame(115.20, $fees->commission->base);
        self::assertSame(115.20, $fees->paymentFee->base);
    }

    // ── who took the money ───────────────────────────────────────────

    public function testTheStayCarriesNoPaymentFeeWhereTheHouseWasPaidDirectly(): void
    {
        // The portal brokered the booking and takes its commission, but it
        // processed nothing, so there is no payment to charge a fee for.
        $invoice = $this->invoiceCollectedBy(PaymentCollection::PROPERTY);
        $invoice->addAppartment($this->stay(200.00));

        $fees = $this->calculate($invoice);

        self::assertSame(200.00, $fees->commission->base);
        self::assertSame(0.0, $fees->paymentFee->base);
        self::assertSame(0.0, $fees->paymentFee->amount);
    }

    public function testTheStayCountsWhereThePortalCollectedThePayment(): void
    {
        $invoice = $this->invoiceCollectedBy(PaymentCollection::PORTAL);
        $invoice->addAppartment($this->stay(200.00));

        $fees = $this->calculate($invoice);

        self::assertSame(200.00, $fees->paymentFee->base);
        self::assertSame(2.80, $fees->paymentFee->amount);
    }

    public function testWhatWasRecordedOnTheBookingBeatsWhatTheOriginSaysToday(): void
    {
        // The portal has since started collecting payments. A booking settled
        // directly with the house before that must not be charged for it.
        $invoice = $this->invoiceCollectedBy(PaymentCollection::PORTAL);
        $invoice->getReservations()->first()->setPaymentCollection(PaymentCollection::PROPERTY);
        $invoice->addAppartment($this->stay(200.00));

        $fees = $this->calculate($invoice);

        self::assertSame(0.0, $fees->paymentFee->base);
    }

    public function testOneStayPaidToTheHouseSettlesItForTheWholeInvoice(): void
    {
        // The base covers the invoice, not a single stay, and charging a payment
        // fee on money the portal never saw is the error worth avoiding.
        $invoice = $this->invoiceCollectedBy(PaymentCollection::PORTAL);
        $invoice->addReservation(new Reservation());
        $invoice->addAppartment($this->stay(200.00));

        $fees = $this->calculate($invoice);

        self::assertSame(0.0, $fees->paymentFee->base);
    }

    // ── rates that disagree ──────────────────────────────────────────

    public function testReportsEveryRateFoundSoTheJournalCanRefuseTheInvoice(): void
    {
        // Two portals on one invoice. Which rate holds for it has no answer, and
        // the caller - not this - decides what to do about that.
        $invoice = $this->invoice();
        $invoice->addReservation($this->reservationWithOrigin('Booking.com', '12.00', null));
        $invoice->addReservation($this->reservationWithOrigin('Fewo-direkt', '15.00', null));

        $fees = $this->calculate($invoice, gross: 100.0);

        self::assertFalse($fees->commission->isAgreedUpon());
        self::assertSame(['12,00 %', '15,00 %'], $fees->commission->rateLabels());
    }

    public function testAPortalBookingSharingAnInvoiceWithADirectOneDisagreesToo(): void
    {
        // The direct booking carries no rate at all, which is a rate of zero -
        // and booking 12 % on the whole invoice would overcharge the house.
        $invoice = $this->invoice();
        $invoice->addReservation($this->reservationWithOrigin('Booking.com', '12.00', null));
        $invoice->addReservation(new Reservation());

        $fees = $this->calculate($invoice, gross: 100.0);

        self::assertFalse($fees->commission->isAgreedUpon());
    }

    public function testShowsTheGuestAFigureEvenWhereTheRatesDisagree(): void
    {
        // A note on an invoice and an entry in the journal do not carry the same
        // weight: the guest is told what the first portal charged rather than
        // nothing at all.
        $invoice = $this->invoice();
        $invoice->addReservation($this->reservationWithOrigin('Booking.com', '12.00', null));
        $invoice->addReservation($this->reservationWithOrigin('Fewo-direkt', '15.00', null));

        $fees = $this->calculate($invoice, gross: 100.0);

        self::assertSame('Booking.com', $fees->originName);
        self::assertSame(12.0, $fees->commission->amount);
    }

    public function testSeveralReservationsAgreeingOnTheRateAreNoDisagreement(): void
    {
        $invoice = $this->invoice();
        $invoice->addReservation($this->reservationWithOrigin('Booking.com', '12.00', null));
        $invoice->addReservation($this->reservationWithOrigin('Booking.com', '12.00', null));

        $fees = $this->calculate($invoice, gross: 100.0);

        self::assertTrue($fees->commission->isAgreedUpon());
        self::assertSame(12.0, $fees->commission->amount);
    }

    public function testABookingTakenBeforeTheFeesWereSetUpIsChargedThePaymentFeeOnItsStay(): void
    {
        // The ordinary order of setting this up: bookings come in through an
        // origin with nothing configured, and the fees are filled in afterwards.
        // Such a booking falls back to the origin for its rates, and has to for
        // who collects as well - or the fee is charged on the extras alone.
        $origin = new ReservationOrigin();
        $origin->setName('Booking.com');

        $reservation = new Reservation();
        $reservation->setReservationOrigin($origin);

        $origin->setCommissionPercent('12.00');
        $origin->setPaymentFeePercent('1.40');
        $origin->setPaymentCollection(PaymentCollection::PORTAL);

        $invoice = $this->invoice();
        $invoice->addReservation($reservation);
        $invoice->addAppartment($this->stay(100.0));

        self::assertSame(1.4, $this->calculate($invoice)->paymentFee->amount);
    }

    public function testAnInvoiceWithoutReservationsHasNothingToDisagreeAbout(): void
    {
        $fees = $this->calculate($this->invoice(), gross: 100.0);

        self::assertTrue($fees->commission->isAgreedUpon());
        self::assertSame(0.0, $fees->commission->percent);
    }

    /**
     * @param ?float $gross a total to hand back for either base, instead of
     *                      adding the invoice's positions up. Most cases here
     *                      are about rates, which have no business depending on
     *                      how a position adds up; the two that are about the
     *                      bases pass null and let the real sum run
     */
    private function calculate(Invoice $invoice, ?float $gross = null): OriginFeeBreakdown
    {
        $sums = new InvoiceSumCalculator();

        if (null !== $gross) {
            $stub = $this->createStub(InvoiceSumCalculator::class);
            $stub->method('grossTotal')->willReturn($gross);
            $sums = $stub;
        }

        return (new OriginFeeCalculator($sums))->calculate($invoice);
    }

    private function invoice(): Invoice
    {
        return new Invoice();
    }

    private function invoiceWithOrigin(string $name, ?string $commission, ?string $paymentFee): Invoice
    {
        $invoice = $this->invoice();
        $invoice->addReservation($this->reservationWithOrigin($name, $commission, $paymentFee));

        return $invoice;
    }

    private function reservationWithOrigin(string $name, ?string $commission, ?string $paymentFee): Reservation
    {
        $origin = new ReservationOrigin();
        $origin->setName($name);
        $origin->setCommissionPercent($commission);
        $origin->setPaymentFeePercent($paymentFee);

        $reservation = new Reservation();
        $reservation->setReservationOrigin($origin);

        return $reservation;
    }

    /** A gross-priced position, VAT included, so its price is its gross. */
    private function position(string $description, float $price, bool $brokered = true, bool $commissionable = true): InvoicePosition
    {
        $position = new InvoicePosition();
        $position->setDescription($description);
        $position->setPrice($price);
        $position->setVat(7.0);
        $position->setIncludesVat(true);
        $position->setIsFlatPrice(true);
        $position->setBrokered($brokered);
        $position->setCommissionable($commissionable);

        return $position;
    }

    /** The room nights, gross-priced, so the stay's price is its gross. */
    private function stay(float $price): InvoiceAppartment
    {
        $stay = new InvoiceAppartment();
        $stay->setDescription('Doppelzimmer');
        $stay->setNumber('1');
        $stay->setStartDate(new \DateTime('2026-06-19'));
        $stay->setEndDate(new \DateTime('2026-06-21'));
        $stay->setPersons(2);
        $stay->setBeds(2);
        $stay->setPrice($price);
        $stay->setVat(7.0);
        $stay->setIncludesVat(true);
        $stay->setIsFlatPrice(true);

        return $stay;
    }

    /** An invoice for one 12 % / 1.4 % portal booking, settled as given. */
    private function invoiceCollectedBy(PaymentCollection $collection): Invoice
    {
        $invoice = $this->invoice();
        $reservation = $this->reservationWithOrigin('Booking.com', '12.00', '1.40');
        $reservation->getReservationOrigin()->setPaymentCollection($collection);
        // Assigned after the origin carries its answer - setReservationOrigin()
        // pins what the origin says at that moment, as it does for the rates.
        $reservation->setPaymentCollection($collection);
        $invoice->addReservation($reservation);

        return $invoice;
    }
}
