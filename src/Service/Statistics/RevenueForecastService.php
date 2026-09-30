<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Entity\Enum\InvoiceStatus;
use App\Entity\Reservation;
use App\Entity\Subsidiary;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationRepository;
use App\Service\InvoiceService;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * Revenue the existing reservations are expected to bring, per month of departure.
 *
 * A reservation is valued like the invoice FewohBee would prefill for it today: room price,
 * guest category surcharges and discounts, the booked extras and tourist tax, gross. It counts in
 * the month of its departure, which is when its invoice is usually written, so the forecast lines
 * up with the invoice based turnover statistics.
 *
 * Every reservation counts except those with the status "canceled / no-show" and conflicts.
 * Reservations that already have an invoice are reported separately: their revenue normally shows
 * up in the turnover already. They are valued the same way, not by the amounts of their invoices.
 * Which invoices make a reservation "invoiced" is up to the caller: by default every invoice that
 * is not canceled; the turnover statistics pass the invoice statuses their bars count, so a
 * reservation whose invoice is left out there stays in the forecast and nothing falls between.
 */
class RevenueForecastService
{
    public const MAX_MONTHS = 36;

    public function __construct(
        private readonly ReservationRepository $reservationRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly InvoiceService $invoiceService,
    ) {
    }

    /**
     * @param \DateTimeImmutable $firstMonth      first day of the first month
     * @param \DateTimeImmutable $lastMonth       any day of the last month
     * @param list<int>|null     $invoiceStatuses InvoiceStatus values that make a reservation count as
     *                                            invoiced; null for every status except canceled
     *
     * @return array<string, array{open: array{total: float, room: float, extras: float, touristTax: float, reservations: int}, invoiced: array{total: float, room: float, extras: float, touristTax: float, reservations: int}}> keyed by Y-m, every month of the range
     */
    public function byDepartureMonth(\DateTimeImmutable $firstMonth, \DateTimeImmutable $lastMonth, ?Subsidiary $subsidiary = null, ?array $invoiceStatuses = null): array
    {
        $from = $firstMonth->modify('first day of this month')->setTime(0, 0);
        $toExclusive = $lastMonth->modify('first day of next month')->setTime(0, 0);

        $reservations = $this->reservationRepository->findDepartingForRevenueForecast($from, $toExclusive, $subsidiary);
        $invoiced = $this->invoicedReservationIds($reservations, $invoiceStatuses);

        /** @var array<string, array{open: list<Reservation>, invoiced: list<Reservation>}> $groups */
        $groups = [];
        for ($month = $from; $month < $toExclusive; $month = $month->modify('+1 month')) {
            $groups[$month->format('Y-m')] = ['open' => [], 'invoiced' => []];
        }
        foreach ($reservations as $reservation) {
            $group = isset($invoiced[(int) $reservation->getId()]) ? 'invoiced' : 'open';
            $groups[$reservation->getEndDate()->format('Y-m')][$group][] = $reservation;
        }

        $result = [];
        foreach ($groups as $month => $group) {
            $result[$month] = ['open' => $this->value($group['open']), 'invoiced' => $this->value($group['invoiced'])];
        }

        return $result;
    }

    /**
     * Ids of the reservations that have at least one invoice in one of the given statuses, or one
     * that is not canceled when no statuses are given.
     *
     * @param list<Reservation> $reservations
     * @param list<int>|null    $invoiceStatuses
     *
     * @return array<int, true>
     */
    private function invoicedReservationIds(array $reservations, ?array $invoiceStatuses): array
    {
        $ids = array_map(static fn (Reservation $reservation): int => (int) $reservation->getId(), $reservations);

        $invoiced = [];
        foreach ($this->invoiceRepository->findSummariesByReservationIds($ids) as $reservationId => $invoices) {
            foreach ($invoices as $invoice) {
                $status = (int) $invoice['status'];
                if (null === $invoiceStatuses ? InvoiceStatus::CANCELED->value !== $status : \in_array($status, $invoiceStatuses, true)) {
                    $invoiced[$reservationId] = true;
                    break;
                }
            }
        }

        return $invoiced;
    }

    /**
     * Gross value of the positions a new invoice for these reservations would be prefilled with.
     *
     * @param list<Reservation> $reservations
     *
     * @return array{total: float, room: float, extras: float, touristTax: float, reservations: int}
     */
    private function value(array $reservations): array
    {
        if ([] === $reservations) {
            return ['total' => 0.0, 'room' => 0.0, 'extras' => 0.0, 'touristTax' => 0.0, 'reservations' => 0];
        }

        $roomPositions = [];
        foreach ($reservations as $reservation) {
            array_push($roomPositions, ...$this->invoiceService->buildAppartmentPositions($reservation));
        }

        $room = $this->gross($roomPositions, $this->invoiceService->buildApartmentModifierPositions($reservations));
        $extras = $this->gross([], $this->invoiceService->buildMiscPositions($reservations, true));
        $touristTax = $this->gross([], $this->invoiceService->buildTouristTaxPositions($reservations));

        return [
            'total' => round($room + $extras + $touristTax, 2),
            'room' => round($room, 2),
            'extras' => round($extras, 2),
            'touristTax' => round($touristTax, 2),
            'reservations' => \count($reservations),
        ];
    }

    /**
     * @param list<\App\Entity\InvoiceAppartment> $roomPositions
     * @param list<\App\Entity\InvoicePosition>   $otherPositions
     */
    private function gross(array $roomPositions, array $otherPositions): float
    {
        $vats = [];
        $gross = 0.0;
        $vatTotal = 0.0;
        $roomTotal = 0.0;
        $otherTotal = 0.0;
        $this->invoiceService->calculateSums(new ArrayCollection($roomPositions), new ArrayCollection($otherPositions), $vats, $gross, $vatTotal, $roomTotal, $otherTotal);

        return $gross;
    }
}
