<?php

declare(strict_types=1);

namespace App\Service\Api;

use App\Entity\Appartment;
use App\Entity\Enum\InvoiceStatus;
use App\Repository\AppartmentRepository;
use App\Repository\ReservationRepository;
use App\Service\InvoiceService;
use App\Service\StatisticsService;

/**
 * Statistics queries shared by the REST API and the MCP tools: utilization and turnover series
 * and the booking pace. Parameters are expected to be validated by the caller; the limits below
 * are the shared bounds.
 */
class StatisticsQueryService
{
    public const MAX_MONTHS = 60;
    public const MAX_YEARS = 5;
    public const MAX_PACE_NIGHTS = 366;
    /** Turnover counts everything except canceled invoices unless told otherwise. */
    public const DEFAULT_INVOICE_STATUS = [InvoiceStatus::OPEN->value, InvoiceStatus::PAID->value, InvoiceStatus::PREPAID->value];

    public function __construct(
        private readonly AppartmentRepository $appartmentRepository,
        private readonly StatisticsService $statisticsService,
        private readonly InvoiceService $invoiceService,
        private readonly ReservationRepository $reservationRepository,
    ) {
    }

    /**
     * Bed utilization in percent per month.
     *
     * @param \DateTimeImmutable $start     first day of the first month
     * @param \DateTimeImmutable $end       any day of the last month
     * @param string             $objectId  subsidiary id or 'all'
     * @param int[]              $statusIds reservation status ids; empty for the default
     *
     * @return array{data: list<array{month: string, utilization: float}>, beds: int}
     */
    public function utilizationByMonth(\DateTimeImmutable $start, \DateTimeImmutable $end, string $objectId, array $statusIds): array
    {
        $beds = (int) $this->appartmentRepository->loadSumBedsMinForObject($objectId);
        $beds = 0 === $beds ? 1 : $beds;

        $data = [];
        $yearCache = [];
        $period = new \DatePeriod($start, new \DateInterval('P1M'), $end->modify('first day of next month'));
        foreach ($period as $month) {
            $year = (int) $month->format('Y');
            // loadUtilizationForYear computes all 12 months of a year; cache per year.
            $yearCache[$year] ??= $this->statisticsService->loadUtilizationForYear($objectId, $year, $beds, $statusIds);
            $data[] = [
                'month' => $month->format('Y-m'),
                'utilization' => round($yearCache[$year][(int) $month->format('n') - 1], 2),
            ];
        }

        return ['data' => $data, 'beds' => $beds];
    }

    /**
     * Invoice-based turnover (gross) per year or per month.
     *
     * @param 'year'|'month' $granularity
     * @param int[]          $invoiceStatus InvoiceStatus values
     *
     * @return list<array{year?: int, month?: string, turnover: float}>
     */
    public function turnover(int $startYear, int $endYear, string $granularity, array $invoiceStatus): array
    {
        $data = [];
        for ($year = $startYear; $year <= $endYear; ++$year) {
            if ('year' === $granularity) {
                $data[] = [
                    'year' => $year,
                    'turnover' => round($this->statisticsService->loadTurnoverForYear($this->invoiceService, $year, $invoiceStatus), 2),
                ];
                continue;
            }
            foreach ($this->statisticsService->loadTurnoverForMonth($this->invoiceService, $year, $invoiceStatus) as $index => $turnover) {
                $data[] = [
                    'month' => sprintf('%d-%02d', $year, $index + 1),
                    'turnover' => round($turnover, 2),
                ];
            }
        }

        return $data;
    }

    /**
     * Booking pace: room nights of a stay period booked by a cut-off day, next to the same period
     * and cut-off day one year earlier. Both periods also report what is booked for them now, so
     * the previous year shows how much still came in after its cut-off.
     *
     * Reservation history is not stored: canceled or deleted reservations are missing from every
     * figure, also for the time before they were canceled. The previous year is shifted by one
     * calendar year, not aligned to weekdays.
     *
     * @param \DateTimeImmutable $firstNight first night of the stay period
     * @param \DateTimeImmutable $lastNight  last night of the stay period (inclusive)
     * @param \DateTimeImmutable $cutOff     last booking day that counts (inclusive)
     * @param string             $objectId   subsidiary id or 'all'
     *
     * @return array{rooms: int, current: array<string, mixed>, previousYear: array<string, mixed>}
     */
    public function bookingPace(
        \DateTimeImmutable $firstNight,
        \DateTimeImmutable $lastNight,
        \DateTimeImmutable $cutOff,
        string $objectId,
        ?int $roomCategoryId,
    ): array {
        $rooms = $this->appartmentRepository->findAllByProperty($objectId);
        if (null !== $roomCategoryId) {
            $rooms = array_filter($rooms, static fn (Appartment $room): bool => $room->getRoomCategory()?->getId() === $roomCategoryId);
        }

        return [
            'rooms' => \count($rooms),
            'current' => $this->paceForPeriod($firstNight, $lastNight, $cutOff, $objectId, $roomCategoryId),
            'previousYear' => $this->paceForPeriod($firstNight->modify('-1 year'), $lastNight->modify('-1 year'), $cutOff->modify('-1 year'), $objectId, $roomCategoryId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paceForPeriod(\DateTimeImmutable $firstNight, \DateTimeImmutable $lastNight, \DateTimeImmutable $cutOff, string $objectId, ?int $roomCategoryId): array
    {
        $toExclusive = $lastNight->modify('+1 day');

        return [
            'firstNight' => $firstNight->format('Y-m-d'),
            'lastNight' => $lastNight->format('Y-m-d'),
            'cutOff' => $cutOff->format('Y-m-d'),
            'bookedByCutOff' => $this->countRoomNights(
                $this->reservationRepository->loadBlockingSpansForPeriod($firstNight, $toExclusive, $objectId, $roomCategoryId, $cutOff->modify('+1 day')),
                $firstNight,
                $toExclusive,
            ),
            'bookedNow' => $this->countRoomNights(
                $this->reservationRepository->loadBlockingSpansForPeriod($firstNight, $toExclusive, $objectId, $roomCategoryId),
                $firstNight,
                $toExclusive,
            ),
        ];
    }

    /**
     * Nights of the spans that fall into [$from, $toExclusive), and the number of reservations.
     *
     * @param array<array{appartmentId: int, startDate: string, endDate: string}> $spans
     *
     * @return array{roomNights: int, reservations: int}
     */
    private function countRoomNights(array $spans, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array
    {
        $nights = 0;
        foreach ($spans as $span) {
            $start = max($from, new \DateTimeImmutable($span['startDate']));
            $end = min($toExclusive, new \DateTimeImmutable($span['endDate']));
            if ($end > $start) {
                $nights += (int) $start->diff($end)->days;
            }
        }

        return ['roomNights' => $nights, 'reservations' => \count($spans)];
    }
}
