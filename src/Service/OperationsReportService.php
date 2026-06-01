<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TouristTaxBreakdown;
use App\Entity\Customer;
use App\Entity\Reservation;
use App\Entity\RoomDayStatus;
use App\Entity\Subsidiary;
use App\Entity\Template;
use App\Entity\TouristTax;
use App\Entity\Enum\InvoiceStatus;
use App\Repository\GuestCategoryRepository;
use App\Repository\ReservationRepository;
use App\Repository\TouristTaxRepository;
use Symfony\Component\Intl\Countries;

/**
 * Renders operations report templates.
 */
class OperationsReportService
{
    public function __construct(
        private readonly HousekeepingViewService $housekeepingViewService,
        private readonly MonthlyStatsService $monthlyStatsService,
        private readonly TouristTaxService $touristTaxService,
        private readonly TouristTaxRepository $touristTaxRepository,
        private readonly GuestCategoryRepository $guestCategoryRepository,
        private readonly ReservationRepository $reservationRepository,
    ) {
    }

    /**
     * Build report data for the selected filters.
     *
     * @param string[] $occupancyTypes
     * @param int[]    $statusIds
     */
    public function buildReportData(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?Subsidiary $subsidiary,
        array $occupancyTypes,
        array $statusIds = []
    ): array {
        $rangeView = $this->housekeepingViewService->buildRangeView($start, $end, $subsidiary, $occupancyTypes, 'blocking', $statusIds);

        return [
            'filters' => [
                'start' => $start,
                'end' => $end,
                'subsidiary' => $subsidiary,
                'occupancyTypes' => $occupancyTypes,
                'statusIds' => $statusIds,
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
            $filters = $param['filters'] ?? [];
            $start = $filters['start'] ?? null;
            $end = $filters['end'] ?? null;
            $subsidiary = $filters['subsidiary'] ?? null;
            $statusIds = is_array($filters['statusIds'] ?? null) ? $filters['statusIds'] : [];
            $locale = (string) ($filters['locale'] ?? \Locale::getDefault());
            $hasRange = $start instanceof \DateTimeImmutable && $end instanceof \DateTimeImmutable;

            if (isset($param['statistics']) && is_array($param['statistics']) && !isset($param['statistics']['countryNames'])) {
                $param['statistics']['countryNames'] = Countries::getNames($locale);
            }
            if ($hasRange && $this->shouldIncludeStatistics($template, $param)) {
                $param['statistics'] = $this->buildStatisticsPayload($start, $end, $subsidiary, $locale, $statusIds);
            }
            if ($hasRange && $this->shouldIncludeTouristTax($template, $param)) {
                $param['touristTax'] = $this->buildTouristTaxPayload($start, $end, $subsidiary, $statusIds);
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
     * Quick check if the template references tourist-tax data.
     */
    private function shouldIncludeTouristTax(Template $template, array $param): bool
    {
        if (isset($param['touristTax'])) {
            return false;
        }

        return str_contains($template->getText(), 'touristTax');
    }

    /**
     * Build tourist-tax payload covering the reporting range.
     *
     * Live-calculates breakdowns via TouristTaxService (single source of truth with the invoice
     * pipeline) and aggregates them per (tax × reportGroup or categoryId) so the report template
     * can render rows dynamically without hard-coding category names or tariffs.
     */
    /**
     * @param int[] $statusIds
     */
    private function buildTouristTaxPayload(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?Subsidiary $subsidiary,
        array $statusIds = [],
    ): array {
        $categories = $this->guestCategoryRepository->findActiveForSubsidiary($subsidiary);
        $categoryMeta = [];
        foreach ($categories as $category) {
            $categoryMeta[] = [
                'id' => (int) $category->getId(),
                'name' => $category->getName(),
                'statisticalGroup' => $category->getStatisticalGroup()->value,
                'isCountedInOccupancy' => $category->isCountedInOccupancy(),
                'sortOrder' => $category->getSortOrder(),
            ];
        }

        $monthlyAggregates = [];
        $cursor = $start->modify('first day of this month')->setTime(0, 0);
        $endMonth = $end->modify('first day of this month')->setTime(0, 0);
        while ($cursor <= $endMonth) {
            $monthStart = $cursor;
            $monthEnd = $cursor->modify('last day of this month')->setTime(23, 59, 59);
            $objectId = $subsidiary?->getId() ?? 'all';

            // Only taxes that are both active and valid within this month show up in the report.
            $monthlyTaxes = $this->touristTaxRepository->findActiveForSubsidiaryInRange($subsidiary, $monthStart, $monthEnd);
            $reservations = $this->reservationRepository->loadReservationsForMonth(
                (int) $monthStart->format('n'),
                (int) $monthStart->format('Y'),
                $objectId,
                $statusIds,
            );

            $monthlyAggregates[] = [
                'year' => (int) $monthStart->format('Y'),
                'month' => (int) $monthStart->format('n'),
                'taxes' => $this->aggregateTaxBreakdowns($reservations, $monthStart, $monthEnd, $monthlyTaxes),
            ];

            $cursor = $cursor->modify('first day of next month');
        }

        return [
            'range' => [
                'start' => $start,
                'end' => $end,
            ],
            'guestCategories' => $categoryMeta,
            'months' => $monthlyAggregates,
        ];
    }

    /**
     * @param TouristTax $tax
     * @return array<string, mixed>
     */
    private function buildTaxMeta(TouristTax $tax): array
    {
        $rates = [];
        foreach ($tax->getRates() as $rate) {
            $category = $rate->getGuestCategory();
            $rates[] = [
                'guestCategoryId' => $category ? (int) $category->getId() : null,
                'guestCategoryName' => $category?->getName(),
                'pricePerNight' => $rate->getPricePerNightFloat(),
                'reportGroup' => $rate->getReportGroup(),
            ];
        }

        return [
            'id' => (int) $tax->getId(),
            'name' => $tax->getName(),
            'sortOrder' => $tax->getSortOrder(),
            'appliesOnlyToAdult' => $tax->isAppliesOnlyToAdult(),
            'calculationMode' => $tax->getCalculationMode()->value,
            'percentageRate' => $tax->getPercentageRateFloat(),
            'percentageBase' => $tax->getPercentageBase()?->value,
            'includesVat' => $tax->isIncludesVat(),
            'rates' => $rates,
        ];
    }

    /**
     * Aggregate TouristTaxBreakdown rows from all reservations into per-tax,
     * per-(reportGroup|categoryId) groups for the given range.
     *
     * Buckets are pre-seeded from the list of taxes valid for this month so a tax
     * with zero matching reservations still renders an explicit (empty) report block —
     * and taxes outside the validity window are dropped entirely.
     *
     * @param iterable<Reservation> $reservations
     * @param TouristTax[] $monthlyTaxes
     * @return array<int, array<string, mixed>>
     */
    private function aggregateTaxBreakdowns(
        iterable $reservations,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
        array $monthlyTaxes,
    ): array {
        $taxMeta = [];
        $byTax = [];
        foreach ($monthlyTaxes as $tax) {
            $taxId = (int) $tax->getId();
            $taxMeta[$taxId] = $this->buildTaxMeta($tax);
            $byTax[$taxId] = [
                'name' => $tax->getName(),
                'sortOrder' => $tax->getSortOrder(),
                'totalNights' => 0,
                'totalAmount' => 0.0,
                'groups' => [],
            ];
        }

        $rangeStartDt = \DateTime::createFromImmutable($rangeStart);
        $rangeEndDt = \DateTime::createFromImmutable($rangeEnd);

        foreach ($reservations as $reservation) {
            if (!$reservation instanceof Reservation) {
                continue;
            }
            $rows = $this->touristTaxService->calculateForReservation($reservation, $rangeStartDt, $rangeEndDt);
            foreach ($rows as $row) {
                // Defensive: ignore stray breakdowns for taxes not in this month's valid set.
                if (!isset($byTax[$row->taxId])) {
                    continue;
                }
                $this->mergeBreakdown($byTax, $row);
            }
        }

        // Sort taxes by their configured sortOrder (then by name as tie-breaker).
        uasort(
            $byTax,
            static fn (array $a, array $b): int => ($a['sortOrder'] <=> $b['sortOrder']) ?: strcmp((string) $a['name'], (string) $b['name'])
        );

        $result = [];
        foreach ($byTax as $taxId => $taxBucket) {
            $groups = array_values($taxBucket['groups']);
            usort($groups, static fn (array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']));
            $meta = $taxMeta[$taxId];
            $result[$taxId] = [
                'taxId' => $taxId,
                'name' => $meta['name'],
                'sortOrder' => $meta['sortOrder'],
                'appliesOnlyToAdult' => $meta['appliesOnlyToAdult'],
                'calculationMode' => $meta['calculationMode'],
                'percentageRate' => $meta['percentageRate'],
                'percentageBase' => $meta['percentageBase'],
                'totalNights' => $taxBucket['totalNights'],
                'totalAmount' => round($taxBucket['totalAmount'], 2),
                'groups' => $groups,
            ];
        }

        return $result;
    }

    /**
     * Merge one TouristTaxBreakdown into the running aggregation map.
     *
     * @param array<int, array<string, mixed>> $byTax
     */
    private function mergeBreakdown(array &$byTax, TouristTaxBreakdown $row): void
    {
        $taxId = $row->taxId;
        if (!isset($byTax[$taxId])) {
            $byTax[$taxId] = [
                'name' => $row->taxName,
                'totalNights' => 0,
                'totalAmount' => 0.0,
                'groups' => [],
            ];
        }

        $reportGroup = $row->reportGroup;
        $groupKey = (null !== $reportGroup && '' !== $reportGroup) ? 'g:'.$reportGroup : 'c:'.$row->categoryId;
        $label = (null !== $reportGroup && '' !== $reportGroup) ? $reportGroup : $row->categoryName;

        if (!isset($byTax[$taxId]['groups'][$groupKey])) {
            $byTax[$taxId]['groups'][$groupKey] = [
                'label' => $label,
                'reportGroup' => $reportGroup,
                'pricePerNight' => $row->pricePerNight,
                'pricePerNightConsistent' => true,
                'totalNights' => 0,
                'totalCount' => 0,
                'totalAmount' => 0.0,
                'categoryIds' => [],
            ];
        }
        $group = &$byTax[$taxId]['groups'][$groupKey];

        // Detect inconsistent tariffs across rates merged into the same report group (>1 cent diff).
        if (abs($group['pricePerNight'] - $row->pricePerNight) > 0.005) {
            $group['pricePerNightConsistent'] = false;
        }

        $nights = $row->nights * $row->count;
        $amount = $row->total();
        $group['totalNights'] += $nights;
        $group['totalCount'] += $row->count;
        $group['totalAmount'] = round($group['totalAmount'] + $amount, 2);
        if (0 !== $row->categoryId && !in_array($row->categoryId, $group['categoryIds'], true)) {
            $group['categoryIds'][] = $row->categoryId;
        }
        unset($group);

        $byTax[$taxId]['totalNights'] += $nights;
        $byTax[$taxId]['totalAmount'] = round($byTax[$taxId]['totalAmount'] + $amount, 2);
    }

    /**
     * Build statistics payload using monthly snapshot metrics for the date range.
     */
    /**
     * @param int[] $statusIds
     */
    private function buildStatisticsPayload(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?Subsidiary $subsidiary,
        string $locale,
        array $statusIds = [],
    ): array {
        $cursor = $start->modify('first day of this month');
        $endMonth = $end->modify('first day of this month');
        $months = [];

        while ($cursor <= $endMonth) {
            $month = (int) $cursor->format('n');
            $year = (int) $cursor->format('Y');
            $payload = $this->monthlyStatsService->buildMetrics($month, $year, $subsidiary);
            $metrics = $payload['metrics'];
            if (!empty($statusIds)) {
                $metrics = $this->monthlyStatsService->filterMetricsByStatus($metrics, $statusIds);
            }
            $months[] = [
                'year' => $year,
                'month' => $month,
                'metrics' => $metrics,
                'warnings' => $payload['warnings'],
            ];
            $cursor = $cursor->modify('first day of next month');
        }

        return [
            'range' => [
                'start' => $start,
                'end' => $end,
            ],
            'countryNames' => Countries::getNames($locale),
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
            InvoiceStatus::PAID->value => InvoiceStatus::PAID->labelKey(),
            InvoiceStatus::PREPAID->value => InvoiceStatus::PREPAID->labelKey(),
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
