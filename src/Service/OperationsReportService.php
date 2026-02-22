<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Customer;
use App\Entity\Reservation;
use App\Entity\RoomDayStatus;
use App\Entity\Subsidiary;
use App\Entity\Template;
use App\Enum\InvoiceStatus;

/**
 * Renders operations report templates.
 */
class OperationsReportService
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
            'invoiceStatusLabels' => $this->getInvoiceStatusLabels(),
        ];
    }

    /**
     * Build all operations report variables required by template rendering.
     */
    public function buildTemplateRenderParams(Template $template, mixed $param): array
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
        $dayKey = $start->format('Y-m-d');
        $dayView = $rangeView['dayViews'][$dayKey] ?? ['rows' => []];
        $weekDays = $this->buildWeekDays($rangeView);
        $occupancyLabelKeys = $this->housekeepingViewService->getOccupancyLabels();
        $statusLabelKeys = $this->housekeepingViewService->getStatusLabels();
        $invoiceStatusLabels = $this->getInvoiceStatusLabels();
        $reservationRows = $this->buildSimpleReservationRows($rangeView, $dayKey, $invoiceStatusLabels);
        $housekeepingDayRows = $this->buildSimpleHousekeepingDayRows($dayView, $occupancyLabelKeys, $statusLabelKeys, $dayKey);
        $housekeepingWeekRows = $this->buildSimpleHousekeepingWeekRows($rangeView, $weekDays, $occupancyLabelKeys, $statusLabelKeys);
        $frontdeskSections = $this->buildSimpleFrontdeskSections($reservationRows);
        $mealsRows = $this->buildSimpleMealsRows($rangeView, $weekDays);

        return [
            'meta' => [
                'periodLabel' => $this->buildPeriodLabel($start, $end),
                'generatedAt' => (new \DateTimeImmutable('now'))->format('d.m.Y H:i'),
                'subsidiaryName' => $subsidiary?->getName(),
                'subsidiaryAllLabelKey' => 'housekeeping.subsidiary.all',
                'occupancyTypeLabelKeys' => $occupancyTypes,
                'dayKey' => $dayKey,
            ],
            'days' => $weekDays,
            'reservations' => $reservationRows,
            'views' => [
                'housekeepingDay' => [
                    'date' => $start,
                    'rows' => $housekeepingDayRows,
                ],
                'housekeepingWeek' => [
                    'rows' => $housekeepingWeekRows,
                ],
                'frontdesk' => [
                    'sections' => $frontdeskSections,
                ],
                'mealsChecklist' => [
                    'rows' => $mealsRows,
                ],
            ],
        ];
    }

    /**
     * Build a period label for report headers.
     */
    private function buildPeriodLabel(\DateTimeImmutable $start, \DateTimeImmutable $end): string
    {
        $periodLabel = $start->format('d.m.Y');
        if ($start->format('Y-m-d') !== $end->format('Y-m-d')) {
            $periodLabel .= ' - '.$end->format('d.m.Y');
        }

        return $periodLabel;
    }

    /**
     * Build reusable day metadata for the selected date range.
     *
     * @param array<string, mixed> $rangeView
     * @return array<int, array<string, mixed>>
     */
    private function buildWeekDays(array $rangeView): array
    {
        $weekDays = [];
        foreach (($rangeView['days'] ?? []) as $day) {
            if (!$day instanceof \DateTimeImmutable) {
                continue;
            }
            $dayKey = $day->format('Y-m-d');
            $weekDays[] = [
                'date' => $day,
                'dateKey' => $dayKey,
                'label' => $day->format('d.m.'),
            ];
        }

        return $weekDays;
    }

    /**
     * Build normalized reservation rows used by multiple simple report views.
     *
     * @param array<string, mixed> $rangeView
     * @param array<string, string> $invoiceStatusLabels
     * @return array<int, array<string, mixed>>
     */
    private function buildSimpleReservationRows(array $rangeView, string $dayKey, array $invoiceStatusLabels): array
    {
        $reservationRows = [];
        foreach (($rangeView['reservations'] ?? []) as $reservation) {
            if (!$reservation instanceof Reservation) {
                continue;
            }

            $apartment = $reservation->getAppartment();
            $roomNumber = null !== $apartment ? trim((string) $apartment->getNumber()) : '';
            $roomDescription = null !== $apartment ? trim((string) $apartment->getDescription()) : '';
            $roomLabel = trim($roomNumber.' '.$roomDescription);
            if ('' === $roomLabel) {
                $roomLabel = '-';
            }

            $startKey = $reservation->getStartDate()->format('Y-m-d');
            $endKey = $reservation->getEndDate()->format('Y-m-d');

            $frontdeskSectionKey = null;
            if ($startKey === $dayKey) {
                $frontdeskSectionKey = 'operations.frontdesk.arrivals';
            } elseif ($endKey === $dayKey) {
                $frontdeskSectionKey = 'operations.frontdesk.departures';
            } elseif ($startKey < $dayKey && $endKey > $dayKey) {
                $frontdeskSectionKey = 'operations.frontdesk.inhouse';
            }

            $invoiceStatusLabelKey = null;
            $firstInvoice = $reservation->getInvoices()->first();
            if (false !== $firstInvoice && null !== $firstInvoice && method_exists($firstInvoice, 'getStatus')) {
                $status = $firstInvoice->getStatus();
                $invoiceStatusLabelKey = null !== $status ? ($invoiceStatusLabels[$status] ?? null) : null;
            }

            $reservationRows[] = [
                'roomNumber' => '' !== $roomNumber ? $roomNumber : '-',
                'roomLabel' => $roomLabel,
                'guest' => $this->resolveReservationGuestLabel($reservation),
                'persons' => $reservation->getPersons() ?? '-',
                'start' => $reservation->getStartDate(),
                'end' => $reservation->getEndDate(),
                'period' => $reservation->getStartDate()->format('d.m.Y').' - '.$reservation->getEndDate()->format('d.m.Y'),
                'frontdeskSectionKey' => $frontdeskSectionKey,
                'invoiceStatusLabelKey' => $invoiceStatusLabelKey,
                'apartmentId' => null !== $apartment ? $apartment->getId() : null,
            ];
        }

        return $reservationRows;
    }

    /**
     * Build simple housekeeping day rows from the selected day view.
     *
     * @param array<string, mixed> $dayView
     * @param array<string, string> $occupancyLabelKeys
     * @param array<string, string> $statusLabelKeys
     * @return array<int, array<string, mixed>>
     */
    private function buildSimpleHousekeepingDayRows(
        array $dayView,
        array $occupancyLabelKeys,
        array $statusLabelKeys,
        string $dayKey
    ): array {
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

        return $housekeepingDayRows;
    }

    /**
     * Build simple housekeeping week matrix grouped by apartment.
     *
     * @param array<string, mixed> $rangeView
     * @param array<int, array<string, mixed>> $weekDays
     * @param array<string, string> $occupancyLabelKeys
     * @param array<string, string> $statusLabelKeys
     * @return array<int, array<string, mixed>>
     */
    private function buildSimpleHousekeepingWeekRows(
        array $rangeView,
        array $weekDays,
        array $occupancyLabelKeys,
        array $statusLabelKeys
    ): array {
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

        return $housekeepingWeekRows;
    }

    /**
     * Group normalized reservations into frontdesk sections.
     *
     * @param array<int, array<string, mixed>> $reservationRows
     * @return array<int, array<string, mixed>>
     */
    private function buildSimpleFrontdeskSections(array $reservationRows): array
    {
        $frontdeskSections = [
            [
                'titleKey' => 'operations.frontdesk.arrivals',
                'rows' => [],
            ],
            [
                'titleKey' => 'operations.frontdesk.departures',
                'rows' => [],
            ],
            [
                'titleKey' => 'operations.frontdesk.inhouse',
                'rows' => [],
            ],
        ];
        $sectionIndexByKey = [
            'operations.frontdesk.arrivals' => 0,
            'operations.frontdesk.departures' => 1,
            'operations.frontdesk.inhouse' => 2,
        ];

        foreach ($reservationRows as $reservationRow) {
            $sectionKey = $reservationRow['frontdeskSectionKey'] ?? null;
            if (!is_string($sectionKey) || !isset($sectionIndexByKey[$sectionKey])) {
                continue;
            }
            $frontdeskSections[$sectionIndexByKey[$sectionKey]]['rows'][] = [
                'guest' => $reservationRow['guest'] ?? '-',
                'persons' => $reservationRow['persons'] ?? '-',
                'room' => $reservationRow['roomNumber'] ?? '-',
                'period' => $reservationRow['period'] ?? '-',
                'invoiceStatusLabelKey' => $reservationRow['invoiceStatusLabelKey'] ?? null,
            ];
        }

        return $frontdeskSections;
    }

    /**
     * Build a simplified meals checklist matrix (rooms x visible days).
     *
     * @param array<string, mixed> $rangeView
     * @param array<int, array<string, mixed>> $weekDays
     * @return array<int, array<string, mixed>>
     */
    private function buildSimpleMealsRows(array $rangeView, array $weekDays): array
    {
        // Pre-compute the meals checklist table as a flat matrix so templates
        // can render it with simple data-repeat loops only.
        $visibleApartmentIds = [];
        foreach (($rangeView['dayViews'] ?? []) as $view) {
            foreach (($view['rows'] ?? []) as $row) {
                $apartment = $row['apartment'] ?? null;
                $apartmentId = method_exists($apartment, 'getId') ? $apartment->getId() : null;
                if (null !== $apartmentId) {
                    $visibleApartmentIds[$apartmentId] = true;
                }
            }
        }

        $mealsRows = [];
        foreach (($rangeView['apartments'] ?? []) as $apartment) {
            if (!method_exists($apartment, 'getId')) {
                continue;
            }
            $apartmentId = $apartment->getId();
            if (null === $apartmentId || !isset($visibleApartmentIds[$apartmentId])) {
                continue;
            }

            $guestNames = [];
            foreach (($rangeView['reservations'] ?? []) as $reservation) {
                if (!$reservation instanceof Reservation) {
                    continue;
                }
                $reservationApartment = $reservation->getAppartment();
                if (null === $reservationApartment || $reservationApartment->getId() !== $apartmentId) {
                    continue;
                }

                foreach ($reservation->getCustomers() as $customer) {
                    $name = trim((string) $customer->getLastname().', '.(string) $customer->getFirstname(), ', ');
                    if ('' !== $name) {
                        $guestNames[$name] = true;
                    }
                }

                if ($reservation->getCustomers()->isEmpty() && null !== $reservation->getBooker()) {
                    $name = trim((string) $reservation->getBooker()->getLastname().', '.(string) $reservation->getBooker()->getFirstname(), ', ');
                    if ('' !== $name) {
                        $guestNames[$name] = true;
                    }
                }
            }

            $guestNamesList = array_keys($guestNames);
            if ([] === $guestNamesList) {
                $guestNamesList = ['-'];
            }

            $cells = array_fill(0, count($weekDays), '');
            foreach ($guestNamesList as $guestName) {
                $mealsRows[] = [
                    'room' => (string) $apartment->getNumber(),
                    'guest' => $guestName,
                    'cells' => $cells,
                ];
            }
        }

        return $mealsRows;
    }

    /**
     * Reusable map from invoice status values to translation keys.
     *
     * @return array<string, string>
     */
    private function getInvoiceStatusLabels(): array
    {
        return [
            InvoiceStatus::OPEN->value => InvoiceStatus::OPEN->labelKey(),
            InvoiceStatus::PAYED->value => InvoiceStatus::PAYED->labelKey(),
            InvoiceStatus::PREPAYED->value => InvoiceStatus::PREPAYED->labelKey(),
            InvoiceStatus::CANCELED->value => InvoiceStatus::CANCELED->labelKey(),
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
