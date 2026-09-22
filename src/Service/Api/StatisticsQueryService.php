<?php

declare(strict_types=1);

namespace App\Service\Api;

use App\Entity\Enum\InvoiceStatus;
use App\Repository\AppartmentRepository;
use App\Service\InvoiceService;
use App\Service\StatisticsService;

/**
 * Month and year series of utilization and turnover, shared by the REST API and the MCP tools.
 * Parameters are expected to be validated by the caller; the limits below are the shared bounds.
 */
class StatisticsQueryService
{
    public const MAX_MONTHS = 60;
    public const MAX_YEARS = 5;
    /** Turnover counts everything except canceled invoices unless told otherwise. */
    public const DEFAULT_INVOICE_STATUS = [InvoiceStatus::OPEN->value, InvoiceStatus::PAID->value, InvoiceStatus::PREPAID->value];

    public function __construct(
        private readonly AppartmentRepository $appartmentRepository,
        private readonly StatisticsService $statisticsService,
        private readonly InvoiceService $invoiceService,
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
}
