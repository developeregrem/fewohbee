<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Entity\Enum\InvoiceStatus;
use App\Entity\Enum\PaymentMeansCode;
use App\Workflow\Condition\HasBookerEmailCondition;
use App\Workflow\Condition\InvoiceHasEmailCondition;
use App\Workflow\Condition\InvoiceStatusCondition;
use App\Workflow\Condition\PaymentMeansCodeCondition;
use App\Workflow\Condition\ReservationHasInvoiceCondition;
use App\Workflow\Condition\ReservationStatusCondition;
use PHPUnit\Framework\TestCase;

final class WorkflowConditionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // InvoiceStatusCondition
    // -------------------------------------------------------------------------

    public function testInvoiceStatusConditionMatchesCorrectStatus(): void
    {
        $condition = new InvoiceStatusCondition();
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getStatus')->willReturn(1);

        self::assertTrue($condition->evaluate(['status' => 1], $invoice, []));
    }

    public function testInvoiceStatusConditionReturnsFalseOnMismatch(): void
    {
        $condition = new InvoiceStatusCondition();
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getStatus')->willReturn(2);

        self::assertFalse($condition->evaluate(['status' => 1], $invoice, []));
    }

    public function testInvoiceStatusConditionReturnsFalseForWrongEntityType(): void
    {
        $condition = new InvoiceStatusCondition();
        $reservation = $this->createStub(Reservation::class);

        self::assertFalse($condition->evaluate(['status' => 1], $reservation, []));
    }

    public function testInvoiceStatusConditionReturnsFalseWhenConfigMissing(): void
    {
        $condition = new InvoiceStatusCondition();
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getStatus')->willReturn(1);

        self::assertFalse($condition->evaluate([], $invoice, []));
    }

    // -------------------------------------------------------------------------
    // InvoiceHasEmailCondition
    // -------------------------------------------------------------------------

    public function testInvoiceHasEmailReturnsTrueWhenEmailSet(): void
    {
        $condition = new InvoiceHasEmailCondition();
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getEmail')->willReturn('guest@example.com');

        self::assertTrue($condition->evaluate([], $invoice, []));
    }

    public function testInvoiceHasEmailReturnsFalseWhenEmailNull(): void
    {
        $condition = new InvoiceHasEmailCondition();
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getEmail')->willReturn(null);

        self::assertFalse($condition->evaluate([], $invoice, []));
    }

    public function testInvoiceHasEmailReturnsFalseWhenEmailBlank(): void
    {
        $condition = new InvoiceHasEmailCondition();
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getEmail')->willReturn('   ');

        self::assertFalse($condition->evaluate([], $invoice, []));
    }

    public function testInvoiceHasEmailReturnsFalseForWrongEntityType(): void
    {
        $condition = new InvoiceHasEmailCondition();
        $reservation = $this->createStub(Reservation::class);

        self::assertFalse($condition->evaluate([], $reservation, []));
    }

    // -------------------------------------------------------------------------
    // HasBookerEmailCondition
    // -------------------------------------------------------------------------

    public function testHasBookerEmailReturnsTrueWhenEmailPresent(): void
    {
        $condition = new HasBookerEmailCondition();

        $address = $this->createStub(CustomerAddresses::class);
        $address->method('getEmail')->willReturn('guest@example.com');

        $booker = $this->createStub(Customer::class);
        $booker->method('getCustomerAddresses')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([$address]));

        $reservation = $this->createStub(Reservation::class);
        $reservation->method('getBooker')->willReturn($booker);

        self::assertTrue($condition->evaluate([], $reservation, []));
    }

    public function testHasBookerEmailReturnsFalseWhenEmailBlank(): void
    {
        $condition = new HasBookerEmailCondition();

        $address = $this->createStub(CustomerAddresses::class);
        $address->method('getEmail')->willReturn('  ');

        $booker = $this->createStub(Customer::class);
        $booker->method('getCustomerAddresses')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([$address]));

        $reservation = $this->createStub(Reservation::class);
        $reservation->method('getBooker')->willReturn($booker);

        self::assertFalse($condition->evaluate([], $reservation, []));
    }

    public function testHasBookerEmailReturnsFalseWhenNoBooker(): void
    {
        $condition = new HasBookerEmailCondition();
        $reservation = $this->createStub(Reservation::class);
        $reservation->method('getBooker')->willReturn(null);

        self::assertFalse($condition->evaluate([], $reservation, []));
    }

    public function testHasBookerEmailReturnsFalseWhenNoAddresses(): void
    {
        $condition = new HasBookerEmailCondition();

        $booker = $this->createStub(Customer::class);
        $booker->method('getCustomerAddresses')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        $reservation = $this->createStub(Reservation::class);
        $reservation->method('getBooker')->willReturn($booker);

        self::assertFalse($condition->evaluate([], $reservation, []));
    }

    public function testHasBookerEmailReturnsFalseForWrongEntityType(): void
    {
        $condition = new HasBookerEmailCondition();
        $invoice = $this->createStub(Invoice::class);

        self::assertFalse($condition->evaluate([], $invoice, []));
    }

    // -------------------------------------------------------------------------
    // ReservationStatusCondition
    // -------------------------------------------------------------------------

    public function testReservationStatusConditionMatchesCorrectStatus(): void
    {
        $condition = new ReservationStatusCondition();

        $status = $this->createStub(ReservationStatus::class);
        $status->method('getId')->willReturn(3);

        $reservation = $this->createStub(Reservation::class);
        $reservation->method('getReservationStatus')->willReturn($status);

        self::assertTrue($condition->evaluate(['statusId' => 3], $reservation, []));
    }

    public function testReservationStatusConditionReturnsFalseOnMismatch(): void
    {
        $condition = new ReservationStatusCondition();

        $status = $this->createStub(ReservationStatus::class);
        $status->method('getId')->willReturn(2);

        $reservation = $this->createStub(Reservation::class);
        $reservation->method('getReservationStatus')->willReturn($status);

        self::assertFalse($condition->evaluate(['statusId' => 3], $reservation, []));
    }

    public function testReservationStatusConditionReturnsFalseWhenStatusNull(): void
    {
        $condition = new ReservationStatusCondition();
        $reservation = $this->createStub(Reservation::class);
        $reservation->method('getReservationStatus')->willReturn(null);

        self::assertFalse($condition->evaluate(['statusId' => 3], $reservation, []));
    }

    public function testReservationStatusConditionReturnsFalseForWrongEntityType(): void
    {
        $condition = new ReservationStatusCondition();
        $invoice = $this->createStub(Invoice::class);

        self::assertFalse($condition->evaluate(['statusId' => 1], $invoice, []));
    }

    // -------------------------------------------------------------------------
    // PaymentMeansCodeCondition
    // -------------------------------------------------------------------------

    public function testPaymentMeansCodeConditionMatchesCorrectCode(): void
    {
        $condition = new PaymentMeansCodeCondition();
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getPaymentMeans')->willReturn(PaymentMeansCode::CASH);

        self::assertTrue($condition->evaluate(['paymentMeansCode' => 10], $invoice, []));
    }

    public function testPaymentMeansCodeConditionReturnsFalseOnMismatch(): void
    {
        $condition = new PaymentMeansCodeCondition();
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getPaymentMeans')->willReturn(PaymentMeansCode::SEPA_CREDIT_TRANSFER);

        self::assertFalse($condition->evaluate(['paymentMeansCode' => 10], $invoice, []));
    }

    public function testPaymentMeansCodeConditionReturnsFalseWhenPaymentMeansNull(): void
    {
        $condition = new PaymentMeansCodeCondition();
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getPaymentMeans')->willReturn(null);

        self::assertFalse($condition->evaluate(['paymentMeansCode' => 10], $invoice, []));
    }

    public function testPaymentMeansCodeConditionReturnsFalseForWrongEntityType(): void
    {
        $condition = new PaymentMeansCodeCondition();
        $reservation = $this->createStub(Reservation::class);

        self::assertFalse($condition->evaluate(['paymentMeansCode' => 10], $reservation, []));
    }

    public function testPaymentMeansCodeConditionReturnsFalseWhenConfigMissing(): void
    {
        $condition = new PaymentMeansCodeCondition();
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getPaymentMeans')->willReturn(PaymentMeansCode::CASH);

        self::assertFalse($condition->evaluate([], $invoice, []));
    }

    // -------------------------------------------------------------------------
    // ReservationHasInvoiceCondition
    // -------------------------------------------------------------------------

    private function reservationWithInvoices(int ...$statuses): Reservation
    {
        $reservation = new Reservation();
        foreach ($statuses as $status) {
            $invoice = new Invoice();
            $invoice->setStatus($status);
            $reservation->addInvoice($invoice);
        }

        return $reservation;
    }

    public function testHasInvoiceConditionFailsForAFreshOnlineBooking(): void
    {
        $condition = new ReservationHasInvoiceCondition();

        self::assertFalse($condition->evaluate(['status' => 'any'], new Reservation(), []));
    }

    public function testHasInvoiceConditionMatchesAnyNonCancelledInvoice(): void
    {
        $condition = new ReservationHasInvoiceCondition();
        $reservation = $this->reservationWithInvoices(InvoiceStatus::PAID->value);

        self::assertTrue($condition->evaluate(['status' => 'any'], $reservation, []));
    }

    public function testHasInvoiceConditionIgnoresCancelledInvoices(): void
    {
        $condition = new ReservationHasInvoiceCondition();
        $reservation = $this->reservationWithInvoices(InvoiceStatus::CANCELED->value);

        self::assertFalse($condition->evaluate(['status' => 'any'], $reservation, []));
    }

    public function testHasInvoiceConditionOpenModeIgnoresPaidInvoices(): void
    {
        $condition = new ReservationHasInvoiceCondition();
        $reservation = $this->reservationWithInvoices(InvoiceStatus::PAID->value, InvoiceStatus::PREPAID->value);

        self::assertFalse($condition->evaluate(['status' => 'open'], $reservation, []));
        self::assertTrue($condition->evaluate(['status' => 'any'], $reservation, []));
    }

    public function testHasInvoiceConditionOpenModeMatchesUnpaidInvoice(): void
    {
        $condition = new ReservationHasInvoiceCondition();
        $reservation = $this->reservationWithInvoices(InvoiceStatus::PAID->value, InvoiceStatus::OPEN->value);

        self::assertTrue($condition->evaluate(['status' => 'open'], $reservation, []));
    }

    public function testHasInvoiceConditionDefaultsToAnyWhenConfigMissing(): void
    {
        $condition = new ReservationHasInvoiceCondition();
        $reservation = $this->reservationWithInvoices(InvoiceStatus::PAID->value);

        self::assertTrue($condition->evaluate([], $reservation, []));
    }

    public function testHasInvoiceConditionChecksTheWholeBookingGroup(): void
    {
        $condition = new ReservationHasInvoiceCondition();
        $withoutInvoice = new Reservation();
        $withInvoice = $this->reservationWithInvoices(InvoiceStatus::OPEN->value);

        self::assertTrue($condition->evaluate(
            ['status' => 'any'],
            $withoutInvoice,
            ['allReservations' => [$withoutInvoice, $withInvoice]]
        ));
    }

    public function testHasInvoiceConditionReturnsFalseForWrongEntityType(): void
    {
        $condition = new ReservationHasInvoiceCondition();

        self::assertFalse($condition->evaluate(['status' => 'any'], new Invoice(), []));
    }
}
