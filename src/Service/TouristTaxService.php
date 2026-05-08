<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TouristTaxBreakdown;
use App\Entity\Reservation;
use App\Entity\Subsidiary;
use App\Entity\TouristTax;
use App\Entity\TouristTaxRate;
use App\Repository\GuestCategoryRepository;
use App\Repository\TouristTaxRepository;

class TouristTaxService
{
    public function __construct(
        private readonly TouristTaxRepository $touristTaxRepository,
        private readonly GuestCategoryRepository $guestCategoryRepository,
    ) {
    }

    /**
     * @return TouristTaxBreakdown[]
     */
    public function calculateForReservation(Reservation $reservation): array
    {
        if ($reservation->isKurtaxeWaived()) {
            return [];
        }

        $start = $reservation->getStartDate();
        $end = $reservation->getEndDate();
        if (!$start instanceof \DateTimeInterface || !$end instanceof \DateTimeInterface) {
            return [];
        }
        $totalNights = max(1, (int) $start->diff($end)->format('%a'));

        $guestCounts = $reservation->getGuestCounts();
        if (empty($guestCounts)) {
            return [];
        }

        // Each overnight is attributed to its arrival day (= the night that
        // starts on date X). The last day of the reservation is the checkout
        // day, no overnight there. Per-night validity check below means a tax
        // that ends mid-stay only counts the nights it actually covers.
        $lastNightDate = (clone $start)->modify('+'.($totalNights - 1).' days');
        $subsidiary = $reservation->getAppartment()?->getObject();
        $taxes = $this->touristTaxRepository->findActiveForSubsidiaryInRange($subsidiary, $start, $lastNightDate);
        if (empty($taxes)) {
            return [];
        }

        $categories = [];
        foreach ($this->guestCategoryRepository->findAll() as $gc) {
            $categories[$gc->getId()] = $gc;
        }

        // Aggregate nights per (taxId, categoryId) so each combination yields
        // one breakdown row with the actual covered-nights count.
        $aggregates = [];
        for ($i = 0; $i < $totalNights; ++$i) {
            $night = (clone $start)->modify('+'.$i.' days');
            foreach ($taxes as $tax) {
                if (!$tax->isValidOn($night)) {
                    continue;
                }
                foreach ($tax->getRates() as $rate) {
                    $catId = $rate->getGuestCategory()?->getId();
                    if (null === $catId) {
                        continue;
                    }
                    $count = (int) ($guestCounts[$catId] ?? 0);
                    if ($count <= 0) {
                        continue;
                    }
                    $category = $categories[$catId] ?? $rate->getGuestCategory();
                    if ($tax->isAppliesOnlyToAdult() && !$category?->isAdult()) {
                        continue;
                    }

                    $key = $tax->getId().':'.$catId;
                    if (!isset($aggregates[$key])) {
                        $aggregates[$key] = ['tax' => $tax, 'rate' => $rate, 'count' => $count, 'nights' => 0];
                    }
                    ++$aggregates[$key]['nights'];
                }
            }
        }

        $result = [];
        foreach ($aggregates as $a) {
            $result[] = $this->makeBreakdown($a['tax'], $a['rate'], $a['nights'], $a['count']);
        }

        return $result;
    }

    public function hasActiveTaxForSubsidiary(?Subsidiary $subsidiary): bool
    {
        return $this->touristTaxRepository->hasActiveForSubsidiary($subsidiary);
    }

    private function makeBreakdown(TouristTax $tax, TouristTaxRate $rate, int $nights, int $count): TouristTaxBreakdown
    {
        $category = $rate->getGuestCategory();

        return new TouristTaxBreakdown(
            taxId: (int) $tax->getId(),
            taxName: $tax->getName(),
            categoryId: (int) $category?->getId(),
            categoryName: $category?->getName() ?? '',
            pricePerNight: $rate->getPricePerNightFloat(),
            nights: $nights,
            count: $count,
            reportGroup: $rate->getReportGroup(),
            taxRate: $tax->getTaxRate(),
            revenueAccount: $tax->getRevenueAccount(),
            includesVat: $tax->isIncludesVat(),
        );
    }
}
