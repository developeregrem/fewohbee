<?php

declare(strict_types=1);

namespace App\Tests\Unit\Statistics;

use App\Entity\Enum\InvoiceStatus;
use App\Entity\Reservation;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationRepository;
use App\Service\InvoiceService;
use App\Service\Statistics\RevenueForecastService;
use PHPUnit\Framework\TestCase;

final class RevenueForecastServiceTest extends TestCase
{
    public function testAnyInvoiceExceptACanceledOneMakesAReservationInvoicedByDefault(): void
    {
        $month = $this->forecast(null)['2027-05'];

        // Without an invoice (1) and with a canceled one (4) stay open; open (2) and paid (3) are invoiced.
        self::assertSame(2, $month['open']['reservations']);
        self::assertSame(2, $month['invoiced']['reservations']);
    }

    public function testOnlyInvoicesInTheGivenStatusesMakeAReservationInvoiced(): void
    {
        // The turnover bars only show paid invoices: the open one must stay in the forecast.
        $month = $this->forecast([InvoiceStatus::PAID->value])['2027-05'];

        self::assertSame(3, $month['open']['reservations']);
        self::assertSame(1, $month['invoiced']['reservations']);
    }

    public function testReservationsCountInTheirDepartureMonthAndEveryMonthIsReported(): void
    {
        $forecast = $this->forecast(null);

        self::assertSame(['2027-05', '2027-06'], array_keys($forecast));
        self::assertSame(1, $forecast['2027-06']['open']['reservations']);
        self::assertSame(0.0, $forecast['2027-06']['invoiced']['total']);
    }

    /**
     * @param list<int>|null $invoiceStatuses
     *
     * @return array<string, array{open: array<string, float|int>, invoiced: array<string, float|int>}>
     */
    private function forecast(?array $invoiceStatuses): array
    {
        $reservations = $this->createStub(ReservationRepository::class);
        $reservations->method('findDepartingForRevenueForecast')->willReturn([
            $this->reservation(1, '2027-05-03'),
            $this->reservation(2, '2027-05-10'),
            $this->reservation(3, '2027-05-20'),
            $this->reservation(4, '2027-05-31'),
            // Arrives in May, departs in June: counts in June.
            $this->reservation(5, '2027-06-02'),
        ]);
        $invoices = $this->createStub(InvoiceRepository::class);
        $invoices->method('findSummariesByReservationIds')->willReturn([
            2 => [['id' => 20, 'number' => 'R-20', 'date' => new \DateTime('2027-05-10'), 'status' => InvoiceStatus::OPEN->value]],
            3 => [['id' => 30, 'number' => 'R-30', 'date' => new \DateTime('2027-05-20'), 'status' => InvoiceStatus::PAID->value]],
            4 => [['id' => 40, 'number' => 'R-40', 'date' => new \DateTime('2027-05-31'), 'status' => InvoiceStatus::CANCELED->value]],
        ]);
        $invoiceService = $this->createStub(InvoiceService::class);
        $invoiceService->method('buildAppartmentPositions')->willReturn([]);
        $invoiceService->method('buildApartmentModifierPositions')->willReturn([]);
        $invoiceService->method('buildMiscPositions')->willReturn([]);
        $invoiceService->method('buildTouristTaxPositions')->willReturn([]);

        return (new RevenueForecastService($reservations, $invoices, $invoiceService))->byDepartureMonth(
            new \DateTimeImmutable('2027-05-01'),
            new \DateTimeImmutable('2027-06-01'),
            null,
            $invoiceStatuses,
        );
    }

    private function reservation(int $id, string $departure): Reservation
    {
        $reservation = new Reservation();
        $reservation->setId($id);
        $reservation->setStartDate(new \DateTime('2027-05-01'));
        $reservation->setEndDate(new \DateTime($departure));

        return $reservation;
    }
}
