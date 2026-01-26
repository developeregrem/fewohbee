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
        private readonly HousekeepingViewService $housekeepingViewService
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
            return $param;
        }

        return [];
    }
}
