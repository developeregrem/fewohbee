<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TouristTaxBreakdown;
use App\Entity\Reservation;
use App\Entity\Subsidiary;
use App\Entity\TouristTax;
use App\Repository\GuestCategoryRepository;
use App\Repository\ReservationRepository;
use App\Repository\TouristTaxRepository;

/**
 * Aggregates tourist-tax breakdowns into the monthly report structure used by the
 * operations report templates and by the REST API alike.
 *
 * Amounts are always live-calculated through {@see TouristTaxService::calculateForReservation()}
 * so the report, the invoice pipeline and the API can never drift apart.
 */
class TouristTaxReportService
{
    public function __construct(
        private readonly TouristTaxService $touristTaxService,
        private readonly TouristTaxRepository $touristTaxRepository,
        private readonly GuestCategoryRepository $guestCategoryRepository,
        private readonly ReservationRepository $reservationRepository,
    ) {
    }

    /**
     * Build tourist-tax payload covering the reporting range.
     *
     * Live-calculates breakdowns via TouristTaxService (single source of truth with the invoice
     * pipeline) and aggregates them per (tax × reportGroup or categoryId) so the report template
     * can render rows dynamically without hard-coding category names or tariffs.
     *
     * @param int[] $statusIds
     *
     * @return array{range: array{start: \DateTimeImmutable, end: \DateTimeImmutable}, guestCategories: list<array<string, mixed>>, months: list<array<string, mixed>>}
     */
    public function buildPayload(
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
     * @param iterable<mixed> $reservations
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
}
