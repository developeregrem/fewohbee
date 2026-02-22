<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Subsidiary;
use App\Entity\Template;
use App\Interfaces\ITemplateRenderer;

/**
 * Renders operations report templates.
 */
class OperationsReportService implements ITemplateRenderer
{
    public function __construct(
        private readonly HousekeepingViewService $housekeepingViewService,
        private readonly MonthlyStatsService $monthlyStatsService
    ) {
    }

    /**
     * Build report data for the selected filters.
     *
     * @param string[] $occupancyTypes
     */
    public function buildReportData(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?Subsidiary $subsidiary,
        array $occupancyTypes
    ): array {
        $rangeView = $this->housekeepingViewService->buildRangeView($start, $end, $subsidiary, $occupancyTypes);

        return [
            'filters' => [
                'start' => $start,
                'end' => $end,
                'subsidiary' => $subsidiary,
                'occupancyTypes' => $occupancyTypes,
            ],
            'rangeView' => $rangeView,
            'dayViews' => $rangeView['dayViews'],
            'reservations' => $rangeView['reservations'],
            'occupancyLabels' => $this->housekeepingViewService->getOccupancyLabels(),
            'statusLabels' => $this->housekeepingViewService->getStatusLabels(),
            'invoiceStatusLabels' => [
                \App\Enum\InvoiceStatus::OPEN->value => \App\Enum\InvoiceStatus::OPEN->labelKey(),
                \App\Enum\InvoiceStatus::PAYED->value => \App\Enum\InvoiceStatus::PAYED->labelKey(),
                \App\Enum\InvoiceStatus::PREPAYED->value => \App\Enum\InvoiceStatus::PREPAYED->labelKey(),
                \App\Enum\InvoiceStatus::CANCELED->value => \App\Enum\InvoiceStatus::CANCELED->labelKey(),
            ],
        ];
    }

    /**
     * Provide parameters for template rendering.
     */
    public function getRenderParams(Template $template, mixed $param)
    {
        if (is_array($param)) {
            if ($this->shouldIncludeStatistics($template, $param)) {
                $filters = $param['filters'] ?? [];
                $start = $filters['start'] ?? null;
                $end = $filters['end'] ?? null;
                $subsidiary = $filters['subsidiary'] ?? null;
                if ($start instanceof \DateTimeImmutable && $end instanceof \DateTimeImmutable) {
                    $param['statistics'] = $this->buildStatisticsPayload($start, $end, $subsidiary);
                }
            }

            return $param;
        }

        return [];
    }

    /**
     * Quick check if the template references statistics data.
     */
    private function shouldIncludeStatistics(Template $template, array $param): bool
    {
        if (isset($param['statistics'])) {
            return false;
        }

        return str_contains($template->getText(), 'statistics.');
    }

    /**
     * Build statistics payload using monthly snapshot metrics for the date range.
     */
    private function buildStatisticsPayload(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?Subsidiary $subsidiary
    ): array {
        $cursor = $start->modify('first day of this month');
        $endMonth = $end->modify('first day of this month');
        $months = [];

        while ($cursor <= $endMonth) {
            $month = (int) $cursor->format('n');
            $year = (int) $cursor->format('Y');
            $payload = $this->monthlyStatsService->buildMetrics($month, $year, $subsidiary);
            $months[] = [
                'year' => $year,
                'month' => $month,
                'metrics' => $payload['metrics'],
                'warnings' => $payload['warnings'],
            ];
            $cursor = $cursor->modify('first day of next month');
        }

        return [
            'range' => [
                'start' => $start,
                'end' => $end,
            ],
            'months' => $months,
        ];
    }
}
