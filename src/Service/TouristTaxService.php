<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TouristTaxBreakdown;
use App\Entity\Enum\TaxCalculationMode;
use App\Entity\GuestCategory;
use App\Entity\Reservation;
use App\Entity\Subsidiary;
use App\Entity\TouristTax;
use App\Entity\TouristTaxRate;
use App\Repository\GuestCategoryRepository;
use App\Repository\TouristTaxRepository;
use Symfony\Contracts\Service\ResetInterface;

class TouristTaxService implements ResetInterface
{
    /**
     * Guest categories and the active taxes of a stay are configuration, asked for again for every
     * reservation and every tax of it. Remembering them for the request keeps a whole year of
     * reservations at one query each. ResetInterface clears them between requests.
     *
     * @var array<int, GuestCategory>|null
     */
    private ?array $guestCategories = null;

    /** @var array<string, list<TouristTax>> keyed by subsidiary and stay range */
    private array $activeTaxes = [];

    public function __construct(
        private readonly TouristTaxRepository $touristTaxRepository,
        private readonly GuestCategoryRepository $guestCategoryRepository,
        private readonly ?PriceService $priceService = null,
    ) {
    }

    public function reset(): void
    {
        $this->guestCategories = null;
        $this->activeTaxes = [];
    }

    /**
     * All guest categories by id, loaded once per request.
     *
     * @return array<int, GuestCategory>
     */
    private function guestCategories(): array
    {
        if (null !== $this->guestCategories) {
            return $this->guestCategories;
        }

        $categories = [];
        foreach ($this->guestCategoryRepository->findAll() as $guestCategory) {
            $categories[$guestCategory->getId()] = $guestCategory;
        }

        return $this->guestCategories = $categories;
    }

    /**
     * The taxes active for that property and stay, loaded once per range and request.
     *
     * @return list<TouristTax>
     */
    private function activeTaxesInRange(?Subsidiary $subsidiary, \DateTimeInterface $start, \DateTimeInterface $lastNight): array
    {
        $key = ($subsidiary?->getId() ?? 0).'|'.$start->format('Y-m-d').'|'.$lastNight->format('Y-m-d');

        return $this->activeTaxes[$key] ??= $this->touristTaxRepository->findActiveForSubsidiaryInRange($subsidiary, $start, $lastNight);
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
        $taxes = $this->activeTaxesInRange($subsidiary, $start, $lastNightDate);
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
        if (null === $rangeStart && null === $rangeEnd) {
            return true;
        }
        // Compare on Y-m-d only — reservation dates come from Doctrine in the app's local timezone
        // while range dates may carry a different timezone (e.g. UTC from filter parsing). A pure
        // timestamp compare would push a "01.06 00:00 Berlin" night below a "01.06 00:00 UTC"
        // rangeStart by the 2h offset, silently dropping the first night of the month.
        $nightKey = $night->format('Y-m-d');
        if (null !== $rangeStart && $nightKey < $rangeStart->format('Y-m-d')) {
            return false;
        }
        if (null !== $rangeEnd && $nightKey > $rangeEnd->format('Y-m-d')) {
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

        $categories = $this->guestCategories();

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

        if (0 === $coveredNights || $apartmentSum <= 0.0) {
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
