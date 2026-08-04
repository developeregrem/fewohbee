<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Enum\PaymentCollection;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use PHPUnit\Framework\TestCase;

/**
 * A reservation records the portal rates it was booked under. What the deduction
 * charges months later has to be the rate of the contract that was in force at
 * the time, not whatever the origin says today.
 */
final class ReservationOriginRatePinningTest extends TestCase
{
    public function testPinsTheOriginsRatesWhenTheOriginIsAssigned(): void
    {
        $reservation = new Reservation();
        $reservation->setReservationOrigin($this->origin('12.00', '1.40'));

        self::assertSame('12.00', $reservation->getCommissionPercent());
        self::assertSame('1.40', $reservation->getPaymentFeePercent());
    }

    public function testKeepsThePinnedRatesWhenTheOriginLaterChangesItsOwn(): void
    {
        $origin = $this->origin('12.00', '1.40');

        $reservation = new Reservation();
        $reservation->setReservationOrigin($origin);

        $origin->setCommissionPercent('18.00');
        $origin->setPaymentFeePercent('2.50');

        self::assertSame('12.00', $reservation->getCommissionPercent());
        self::assertSame('1.40', $reservation->getPaymentFeePercent());
    }

    public function testDoesNotRestampWhenTheSameOriginIsAssignedAgain(): void
    {
        // Re-saving an old reservation must not quietly move it onto today's
        // rates - the form assigns the origin on every save.
        $origin = $this->origin('12.00', '1.40');

        $reservation = new Reservation();
        $reservation->setReservationOrigin($origin);

        $origin->setCommissionPercent('18.00');
        $reservation->setReservationOrigin($origin);

        self::assertSame('12.00', $reservation->getCommissionPercent());
    }

    public function testRepinsWhenTheReservationMovesToAnotherOrigin(): void
    {
        $reservation = new Reservation();
        $reservation->setReservationOrigin($this->origin('12.00', '1.40'));
        $reservation->setReservationOrigin($this->origin('15.00', '2.00'));

        self::assertSame('15.00', $reservation->getCommissionPercent());
        self::assertSame('2.00', $reservation->getPaymentFeePercent());
    }

    public function testRecordsNoRateForAnOriginThatHasNoneConfigured(): void
    {
        // Fees are routinely filled in after the first bookings have arrived.
        // Pinning a zero here would leave those bookings without a deduction for
        // good, even once the origin has its rates.
        $reservation = new Reservation();
        $reservation->setReservationOrigin($this->origin(null, null));

        self::assertNull($reservation->getCommissionPercent());
        self::assertNull($reservation->getPaymentFeePercent());
    }

    public function testPinsARateTheOriginActuallyCarries(): void
    {
        // An origin set to zero has said something, unlike one left blank.
        $reservation = new Reservation();
        $reservation->setReservationOrigin($this->origin('0.00', '1.40'));

        self::assertSame('0.00', $reservation->getCommissionPercent());
        self::assertSame('1.40', $reservation->getPaymentFeePercent());
    }

    public function testClearsThePinnedRatesWhenTheOriginIsRemoved(): void
    {
        $reservation = new Reservation();
        $reservation->setReservationOrigin($this->origin('12.00', '1.40'));
        $reservation->setReservationOrigin(null);

        self::assertNull($reservation->getCommissionPercent());
        self::assertNull($reservation->getPaymentFeePercent());
    }

    public function testPinsWhoCollectsThePaymentAlongWithTheRates(): void
    {
        $origin = $this->origin('12.00', '1.40');
        $origin->setPaymentCollection(PaymentCollection::PORTAL);

        $reservation = new Reservation();
        $reservation->setReservationOrigin($origin);

        self::assertSame(PaymentCollection::PORTAL, $reservation->getPaymentCollection());
    }

    public function testKeepsWhoCollectedWhenTheOriginLaterSwitches(): void
    {
        // A portal that starts collecting payments itself says nothing about the
        // bookings it merely passed on before.
        $origin = $this->origin('12.00', '1.40');

        $reservation = new Reservation();
        $reservation->setReservationOrigin($origin);

        $origin->setPaymentCollection(PaymentCollection::PORTAL);

        self::assertSame(PaymentCollection::PROPERTY, $reservation->getPaymentCollection());
    }

    public function testRecordsNoCollectionForABookingWithoutAnOrigin(): void
    {
        // Unlike the rates there is no blank to guard against - an origin always
        // answers - so only its absence leaves this unrecorded.
        $origin = $this->origin('12.00', '1.40');
        $origin->setPaymentCollection(PaymentCollection::PORTAL);

        $reservation = new Reservation();
        $reservation->setReservationOrigin($origin);
        $reservation->setReservationOrigin(null);

        self::assertNull($reservation->getPaymentCollection());
    }

    private function origin(?string $commission, ?string $paymentFee): ReservationOrigin
    {
        $origin = new ReservationOrigin();
        $origin->setName('Booking.com');
        $origin->setCommissionPercent($commission);
        $origin->setPaymentFeePercent($paymentFee);

        return $origin;
    }
}
