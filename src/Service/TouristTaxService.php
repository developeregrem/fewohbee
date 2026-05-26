<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TouristTaxBreakdown;
use App\Entity\Enum\PercentageBase;
use App\Entity\Enum\TaxCalculationMode;
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
        private readonly ?PriceService $priceService = null,
    ) {
    }

    /**
     * @return TouristTaxBreakdown[]
     */
    public function calculateForReservation(
        Reservation $reservation,
        ?\DateTimeInterface $rangeStart = null,
        ?\DateTimeInterface $rangeEnd = null,
    ): array {
        if ($reservation->isKurtaxeWaived()) {
            return [];
        }

        $start = $reservation->getStartDate();
        $end = $reservation->getEndDate();
        if (!$start instanceof \DateTimeInterface || !$end instanceof \DateTimeInterface) {
            return [];
        }
        $totalNights = max(1, (int) $start->diff($end)->format('%a'));

        $lastNightDate = (clone $start)->modify('+'.($totalNights - 1).' days');
        $subsidiary = $reservation->getAppartment()?->getObject();
        $taxes = $this->touristTaxRepository->findActiveForSubsidiaryInRange($subsidiary, $start, $lastNightDate);
        if (empty($taxes)) {
            return [];
        }

        $result = [];
        foreach ($taxes as $tax) {
            $rows = match ($tax->getCalculationMode()) {
                TaxCalculationMode::PER_NIGHT_FLAT => $this->calculateFlatPerNight($tax, $reservation, $start, $totalNights, $rangeStart, $rangeEnd),
                TaxCalculationMode::PERCENT_PER_ROOM => $this->calculatePercentPerRoom($tax, $reservation, $start, $totalNights, $rangeStart, $rangeEnd),
            };
            foreach ($rows as $row) {
                $result[] = $row;
            }
        }

        return $result;
    }

    public function hasActiveTaxForSubsidiary(?Subsidiary $subsidiary): bool
    {
        return $this->touristTaxRepository->hasActiveForSubsidiary($subsidiary);
    }

    private function nightInRange(
        \DateTimeInterface $night,
        ?\DateTimeInterface $rangeStart,
        ?\DateTimeInterface $rangeEnd,
    ): bool {
        if (null !== $rangeStart && $night < $rangeStart) {
            return false;
        }
        if (null !== $rangeEnd && $night > $rangeEnd) {
            return false;
        }

        return true;
    }

    /**
     * @return TouristTaxBreakdown[]
     */
    private function calculateFlatPerNight(
        TouristTax $tax,
        Reservation $reservation,
        \DateTimeInterface $start,
        int $totalNights,
        ?\DateTimeInterface $rangeStart = null,
        ?\DateTimeInterface $rangeEnd = null,
    ): array {
        $guestCounts = $reservation->getGuestCounts();
        if (empty($guestCounts)) {
            return [];
        }

        $categories = [];
        foreach ($this->guestCategoryRepository->findAll() as $gc) {
            $categories[$gc->getId()] = $gc;
        }

        $aggregates = [];
        for ($i = 0; $i < $totalNights; ++$i) {
            $night = (clone $start)->modify('+'.$i.' days');
            if (!$tax->isValidOn($night)) {
                continue;
            }
            if (!$this->nightInRange($night, $rangeStart, $rangeEnd)) {
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

                $key = $catId;
                if (!isset($aggregates[$key])) {
                    $aggregates[$key] = ['rate' => $rate, 'count' => $count, 'nights' => 0];
                }
                ++$aggregates[$key]['nights'];
            }
        }

        $rows = [];
        foreach ($aggregates as $a) {
            $rows[] = $this->makeFlatBreakdown($tax, $a['rate'], $a['nights'], $a['count']);
        }

        return $rows;
    }

    /**
     * @return TouristTaxBreakdown[]
     */
    private function calculatePercentPerRoom(
        TouristTax $tax,
        Reservation $reservation,
        \DateTimeInterface $start,
        int $totalNights,
        ?\DateTimeInterface $rangeStart = null,
        ?\DateTimeInterface $rangeEnd = null,
    ): array {
        $percent = $tax->getPercentageRateFloat();
        $base = $tax->getPercentageBase();
        if (null === $percent || $percent <= 0.0 || null === $base || null === $this->priceService) {
            return [];
        }

        $apartmentTotals = $this->priceService->getApartmentTotalsPerNight($reservation, $base);

        $coveredNights = 0;
        $apartmentSum = 0.0;
        for ($i = 0; $i < $totalNights; ++$i) {
            $night = (clone $start)->modify('+'.$i.' days');
            if (!$tax->isValidOn($night)) {
                continue;
            }
            if (!$this->nightInRange($night, $rangeStart, $rangeEnd)) {
                continue;
            }
            $key = $night->format('Y-m-d');
            if (!isset($apartmentTotals[$key])) {
                continue;
            }
            $apartmentSum += $apartmentTotals[$key];
            ++$coveredNights;
        }

        if ($coveredNights === 0 || $apartmentSum <= 0.0) {
            return [];
        }

        $total = $apartmentSum * $percent / 100.0;

        return [
            new TouristTaxBreakdown(
                taxId: (int) $tax->getId(),
                taxName: $tax->getName(),
                categoryId: 0,
                categoryName: '',
                pricePerNight: 0.0,
                nights: $coveredNights,
                count: 1,
                reportGroup: null,
                taxRate: $tax->getTaxRate(),
                revenueAccount: $tax->getRevenueAccount(),
                includesVat: $tax->isIncludesVat(),
                calculationMode: TaxCalculationMode::PERCENT_PER_ROOM,
                percentageRate: $percent,
                apartmentBase: round($apartmentSum, 2),
                precomputedTotal: round($total, 2),
            ),
        ];
    }

    private function makeFlatBreakdown(TouristTax $tax, TouristTaxRate $rate, int $nights, int $count): TouristTaxBreakdown
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
            calculationMode: TaxCalculationMode::PER_NIGHT_FLAT,
        );
    }
}
