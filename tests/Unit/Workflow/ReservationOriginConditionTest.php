<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Workflow\Condition\ReservationOriginCondition;
use PHPUnit\Framework\TestCase;

final class ReservationOriginConditionTest extends TestCase
{
    public function testMatchesCorrectOrigin(): void
    {
        $condition = new ReservationOriginCondition();

        $origin = $this->createStub(ReservationOrigin::class);
        $origin->method('getId')->willReturn(5);

        $reservation = $this->createStub(Reservation::class);
        $reservation->method('getReservationOrigin')->willReturn($origin);

        self::assertTrue($condition->evaluate(['originId' => 5], $reservation, []));
    }

    public function testReturnsFalseOnMismatch(): void
    {
        $condition = new ReservationOriginCondition();

        $origin = $this->createStub(ReservationOrigin::class);
        $origin->method('getId')->willReturn(2);

        $reservation = $this->createStub(Reservation::class);
        $reservation->method('getReservationOrigin')->willReturn($origin);

        self::assertFalse($condition->evaluate(['originId' => 5], $reservation, []));
    }

    public function testReturnsFalseWhenOriginIsNull(): void
    {
        $condition = new ReservationOriginCondition();

        $reservation = $this->createStub(Reservation::class);
        $reservation->method('getReservationOrigin')->willReturn(null);

        self::assertFalse($condition->evaluate(['originId' => 5], $reservation, []));
    }

    public function testReturnsFalseForWrongEntityType(): void
    {
        $condition = new ReservationOriginCondition();
        $invoice = $this->createStub(Invoice::class);

        self::assertFalse($condition->evaluate(['originId' => 1], $invoice, []));
    }

    public function testReturnsFalseWhenConfigMissing(): void
    {
        $condition = new ReservationOriginCondition();

        $origin = $this->createStub(ReservationOrigin::class);
        $origin->method('getId')->willReturn(1);

        $reservation = $this->createStub(Reservation::class);
        $reservation->method('getReservationOrigin')->willReturn($origin);

        self::assertFalse($condition->evaluate([], $reservation, []));
    }
}
