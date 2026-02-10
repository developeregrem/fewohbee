<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Customer;
use App\Entity\Reservation;
use App\Entity\RoomDayStatus;
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
            'simple' => $this->buildSimpleTemplateData($start, $end, $subsidiary, $occupancyTypes, $rangeView),
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

    /**
     * Build simplified read-only structures so template authors can use easy
     * data-repeat snippets instead of complex set/if logic.
     *
     * @param string[] $occupancyTypes
     * @param array<string, mixed> $rangeView
     *
     * @return array<string, mixed>
     */
    private function buildSimpleTemplateData(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?Subsidiary $subsidiary,
        array $occupancyTypes,
        array $rangeView
    ): array {
        $periodLabel = $start->format('d.m.Y');
        if ($start->format('Y-m-d') !== $end->format('Y-m-d')) {
            $periodLabel .= ' - '.$end->format('d.m.Y');
        }

        $reservationsRows = [];
        foreach (($rangeView['reservations'] ?? []) as $reservation) {
            if (!$reservation instanceof Reservation) {
                continue;
            }
            $apartment = $reservation->getAppartment();
            $room = '-';
            if (null !== $apartment) {
                $number = trim((string) $apartment->getNumber());
                $description = trim((string) $apartment->getDescription());
                $room = trim($number.' '.$description) ?: '-';
            }

            $reservationsRows[] = [
                'room' => $room,
                'guest' => $this->resolveReservationGuestLabel($reservation),
                'persons' => $reservation->getPersons(),
                'start' => $reservation->getStartDate(),
                'end' => $reservation->getEndDate(),
            ];
        }

        $occupancyLabelKeys = $this->housekeepingViewService->getOccupancyLabels();
        $statusLabelKeys = $this->housekeepingViewService->getStatusLabels();
        $dayKey = $start->format('Y-m-d');
        $dayView = $rangeView['dayViews'][$dayKey] ?? ['rows' => []];

        $housekeepingDayRows = [];
        foreach (($dayView['rows'] ?? []) as $row) {
            $status = ($row['status'] ?? null) instanceof RoomDayStatus ? $row['status'] : null;
            $statusValue = $status?->getHkStatus()->value;
            $statusLabelKey = is_string($statusValue) ? ($statusLabelKeys[$statusValue] ?? null) : null;
            $occupancyType = (string) ($row['occupancyType'] ?? '');
            $apartment = $row['apartment'] ?? null;
            $roomNumber = method_exists($apartment, 'getNumber') ? (string) $apartment->getNumber() : '';
            $roomDescription = method_exists($apartment, 'getDescription') ? (string) $apartment->getDescription() : '';

            $housekeepingDayRows[] = [
                'date' => $dayKey,
                'room' => trim($roomNumber.' '.$roomDescription),
                'occupancyLabelKey' => $occupancyLabelKeys[$occupancyType] ?? $occupancyType,
                'guests' => $row['guestCount'] ?? '',
                'reservationSummary' => $row['reservationSummary'] ?? '',
                'statusLabelKey' => $statusLabelKey,
                'assignedTo' => $status && null !== $status->getAssignedTo()
                    ? trim((string) $status->getAssignedTo()->getFirstname().' '.(string) $status->getAssignedTo()->getLastname())
                    : '',
                'note' => $status?->getNote() ?? '',
            ];
        }

        $weekDays = [];
        foreach (($rangeView['days'] ?? []) as $day) {
            if (!$day instanceof \DateTimeImmutable) {
                continue;
            }
            $dayKey = $day->format('Y-m-d');
            $weekDays[] = [
                'date' => $day,
                'dateKey' => $dayKey,
                'label' => $day->format('d.m'),
            ];
        }

        $weekRowsMap = [];
        foreach (($rangeView['dayViews'] ?? []) as $dateKey => $view) {
            foreach (($view['rows'] ?? []) as $row) {
                $apartment = $row['apartment'] ?? null;
                $apartmentId = method_exists($apartment, 'getId') ? $apartment->getId() : null;
                if (null === $apartmentId) {
                    continue;
                }

                if (!isset($weekRowsMap[$apartmentId])) {
                    $number = method_exists($apartment, 'getNumber') ? (string) $apartment->getNumber() : '';
                    $description = method_exists($apartment, 'getDescription') ? (string) $apartment->getDescription() : '';
                    $weekRowsMap[$apartmentId] = [
                        'apartmentLabel' => trim($number.' '.$description),
                        'cellsByDate' => [],
                    ];
                }

                $status = ($row['status'] ?? null) instanceof RoomDayStatus ? $row['status'] : null;
                $statusValue = $status?->getHkStatus()->value;
                $statusLabelKey = is_string($statusValue) ? ($statusLabelKeys[$statusValue] ?? null) : null;
                $occupancyType = (string) ($row['occupancyType'] ?? '');
                $weekRowsMap[$apartmentId]['cellsByDate'][$dateKey] = [
                    'occupancyLabelKey' => $occupancyLabelKeys[$occupancyType] ?? $occupancyType,
                    'statusLabelKey' => $statusLabelKey,
                    'guests' => $row['guestCount'] ?? '',
                ];
            }
        }

        $housekeepingWeekRows = [];
        foreach ($weekRowsMap as $row) {
            $cells = [];
            foreach ($weekDays as $day) {
                $cell = $row['cellsByDate'][$day['dateKey']] ?? null;
                if (null === $cell) {
                    $cells[] = [
                        'occupancyLabelKey' => '',
                        'statusLabelKey' => '',
                        'guests' => '',
                    ];
                    continue;
                }
                $cells[] = [
                    'occupancyLabelKey' => (string) ($cell['occupancyLabelKey'] ?? ''),
                    'statusLabelKey' => (string) ($cell['statusLabelKey'] ?? ''),
                    'guests' => (string) ($cell['guests'] ?? ''),
                ];
            }

            $housekeepingWeekRows[] = [
                'apartmentLabel' => $row['apartmentLabel'],
                'cells' => $cells,
            ];
        }

        return [
            'periodLabel' => $periodLabel,
            'generatedAt' => (new \DateTimeImmutable('now'))->format('d.m.Y H:i'),
            'subsidiaryLabel' => $subsidiary?->getName() ?: 'alle Niederlassungen',
            'occupancyTypeLabelKeys' => $occupancyTypes,
            'reservationsRows' => $reservationsRows,
            'housekeepingDay' => [
                'date' => $start->format('Y-m-d'),
                'rows' => $housekeepingDayRows,
            ],
            'housekeepingWeek' => [
                'days' => $weekDays,
                'rows' => $housekeepingWeekRows,
            ],
        ];
    }

    /**
     * Resolve a concise guest label for reservations in simple report snippets.
     */
    private function resolveReservationGuestLabel(Reservation $reservation): string
    {
        $names = [];
        foreach ($reservation->getCustomers() as $customer) {
            if (!$customer instanceof Customer) {
                continue;
            }
            $name = trim((string) $customer->getLastname().' '.(string) $customer->getFirstname());
            if ('' !== $name) {
                $names[] = $name;
            }
        }

        if (!empty($names)) {
            return implode(', ', array_unique($names));
        }

        $booker = $reservation->getBooker();
        if ($booker instanceof Customer) {
            $name = trim((string) $booker->getLastname().' '.(string) $booker->getFirstname());
            if ('' !== $name) {
                return $name;
            }
        }

        return '-';
    }
}
