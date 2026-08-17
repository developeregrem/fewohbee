<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Reservation;
use App\Entity\Subsidiary;
use App\Service\InvoiceService;
use PHPUnit\Framework\TestCase;

final class InvoiceServiceResolveSubsidiaryTest extends TestCase
{
    private InvoiceService $invoiceService;

    protected function setUp(): void
    {
        // resolveSubsidiary is a pure function; bypass the constructor.
        $reflection = new \ReflectionClass(InvoiceService::class);
        $this->invoiceService = $reflection->newInstanceWithoutConstructor();
    }

    public function testReturnsNullWithoutReservations(): void
    {
        self::assertNull($this->invoiceService->resolveSubsidiary([]));
    }

    public function testReturnsTheSingleBranch(): void
    {
        $branch = $this->createSubsidiary(1);

        $result = $this->invoiceService->resolveSubsidiary([
            $this->createReservation($branch),
            $this->createReservation($branch),
        ]);

        self::assertSame($branch, $result);
    }

    public function testReturnsNullWhenReservationsSpanBranches(): void
    {
        // Cross-branch invoices are legitimate; they fall back to the global range.
        $result = $this->invoiceService->resolveSubsidiary([
            $this->createReservation($this->createSubsidiary(1)),
            $this->createReservation($this->createSubsidiary(2)),
        ]);

        self::assertNull($result);
    }

    public function testIgnoresReservationsWithoutARoom(): void
    {
        $branch = $this->createSubsidiary(1);

        $result = $this->invoiceService->resolveSubsidiary([
            $this->createReservation(null),
            $this->createReservation($branch),
        ]);

        self::assertSame($branch, $result);
    }

    public function testReturnsNullWhenNoReservationCarriesARoom(): void
    {
        $result = $this->invoiceService->resolveSubsidiary([
            $this->createReservation(null),
        ]);

        self::assertNull($result);
    }

    private function createSubsidiary(int $id): Subsidiary
    {
        $subsidiary = new Subsidiary();
        $subsidiary->setId($id);
        $subsidiary->setName('Branch '.$id);

        return $subsidiary;
    }

    private function createReservation(?Subsidiary $subsidiary): Reservation
    {
        $reservation = new Reservation();

        if (null !== $subsidiary) {
            $appartment = new Appartment();
            $appartment->setObject($subsidiary);
            $reservation->setAppartment($appartment);
        }

        return $reservation;
    }
}
