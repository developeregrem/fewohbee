<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Workflow\Condition\InvoiceReservationOriginCondition;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class InvoiceReservationOriginConditionTest extends TestCase
{
    public function testMatchesCorrectOrigin(): void
    {
        $condition = new InvoiceReservationOriginCondition();
        $invoice = $this->invoiceWithOriginIds(5);

        self::assertTrue($condition->evaluate(['originId' => 5], $invoice, []));
    }

    public function testMatchesWhenOneOfSeveralReservationsHasTheOrigin(): void
    {
        $condition = new InvoiceReservationOriginCondition();
        $invoice = $this->invoiceWithOriginIds(2, 5);

        self::assertTrue($condition->evaluate(['originId' => 5], $invoice, []));
    }

    public function testReturnsFalseOnMismatch(): void
    {
        $condition = new InvoiceReservationOriginCondition();
        $invoice = $this->invoiceWithOriginIds(2);

        self::assertFalse($condition->evaluate(['originId' => 5], $invoice, []));
    }

    public function testReturnsFalseWhenOriginIsNull(): void
    {
        $condition = new InvoiceReservationOriginCondition();
        $invoice = $this->invoiceWithOriginIds(null);

        self::assertFalse($condition->evaluate(['originId' => 5], $invoice, []));
    }

    public function testReturnsFalseWithoutReservations(): void
    {
        $condition = new InvoiceReservationOriginCondition();
        $invoice = $this->invoiceWithOriginIds();

        self::assertFalse($condition->evaluate(['originId' => 5], $invoice, []));
    }

    public function testReturnsFalseForWrongEntityType(): void
    {
        $condition = new InvoiceReservationOriginCondition();
        $reservation = $this->createStub(Reservation::class);

        self::assertFalse($condition->evaluate(['originId' => 1], $reservation, []));
    }

    public function testReturnsFalseWhenConfigMissing(): void
    {
        $condition = new InvoiceReservationOriginCondition();
        $invoice = $this->invoiceWithOriginIds(1);

        self::assertFalse($condition->evaluate([], $invoice, []));
    }

    private function invoiceWithOriginIds(?int ...$originIds): Invoice
    {
        $reservations = [];
        foreach ($originIds as $originId) {
            $origin = null;
            if (null !== $originId) {
                $origin = $this->createStub(ReservationOrigin::class);
                $origin->method('getId')->willReturn($originId);
            }

            $reservation = $this->createStub(Reservation::class);
            $reservation->method('getReservationOrigin')->willReturn($origin);
            $reservations[] = $reservation;
        }

        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getReservations')->willReturn(new ArrayCollection($reservations));

        return $invoice;
    }
}
