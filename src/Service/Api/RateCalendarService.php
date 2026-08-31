<?php

declare(strict_types=1);

namespace App\Service\Api;

use App\Entity\Appartment;
use App\Entity\Price;
use App\Entity\ReservationOrigin;
use App\Repository\PriceRepository;
use App\Service\PriceService;
use App\Service\PublicPricingService;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * Per-night, per-occupancy view of which price row applies to a room category.
 *
 * The rate for a night is not a property of that night alone: findApartmentPrices()
 * filters candidates by minStay and matches numberOfPersons exactly, so "the price on
 * 1 September" only means something once the intended stay length and occupancy are
 * fixed. Both are explicit parameters here rather than assumptions.
 *
 * Costs one query per occupancy, not one per night: the candidate price rows for the
 * whole window are fetched once and PriceService then resolves each night against them
 * in PHP (its $prices parameter exists for exactly this).
 */
class RateCalendarService
{
    public function __construct(
        private readonly PriceService $priceService,
        private readonly PublicPricingService $pricingService,
        private readonly PriceRepository $priceRepository,
    ) {
    }

    /**
     * @param \DateTimeImmutable $firstNight first night to report
     * @param \DateTimeImmutable $lastNight  last night to report (inclusive)
     * @param int                $nights     intended stay length, drives minStay selection
     * @param int[]              $occupancies
     *
     * @return list<array{date: string, rates: list<array<string, mixed>>}>
     */
    public function build(
        Appartment $sampleRoom,
        \DateTimeImmutable $firstNight,
        \DateTimeImmutable $lastNight,
        int $nights,
        array $occupancies,
        ReservationOrigin $origin,
    ): array {
        $dayCount = (int) $firstNight->diff($lastNight)->days + 1;
        // The resolver treats the reservation end as a departure date, so the window has
        // to run one day past the last night for that night to be evaluated at all.
        $windowEnd = $lastNight->modify('+1 day');

        $ratesByDate = [];
        foreach ($occupancies as $occupancy) {
            $reservation = $this->pricingService->buildSampleReservation(
                $sampleRoom,
                $occupancy,
                $firstNight,
                $windowEnd,
                $origin,
            );

            // Period overlap comes from the full window, minStay from the intended stay
            // length — passing the window length here would let a 7-night-only rate show
            // up on a query for single nights.
            $candidates = $this->priceRepository->findApartmentPrices($reservation, $nights);
            if ([] === $candidates) {
                continue;
            }

            $perDay = $this->priceService->getPricesForReservationDays(
                $reservation,
                2,
                new ArrayCollection($candidates),
            );

            for ($i = 0; $i < $dayCount; ++$i) {
                $price = $perDay[$i][0] ?? null;
                if (!$price instanceof Price) {
                    continue;
                }
                $date = $firstNight->modify('+'.$i.' days')->format('Y-m-d');
                $ratesByDate[$date][] = $this->describeRate($price, $occupancy);
            }
        }

        $result = [];
        for ($i = 0; $i < $dayCount; ++$i) {
            $date = $firstNight->modify('+'.$i.' days')->format('Y-m-d');
            $rates = $ratesByDate[$date] ?? [];
            usort($rates, static fn (array $a, array $b): int => $a['occupancy'] <=> $b['occupancy']);
            $result[] = ['date' => $date, 'rates' => $rates];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeRate(Price $price, int $occupancy): array
    {
        $unitPrice = round((float) $price->getPrice(), 2);

        if ($price->getIsFlatPrice()) {
            // A flat price covers the whole stay, so there is no meaningful per-night
            // figure to divide out — the caller gets the stay amount instead.
            $pricingModel = 'flat';
            $perNight = null;
        } elseif ($price->getIsPerRoom()) {
            $pricingModel = 'per_room_night';
            $perNight = $unitPrice;
        } else {
            $pricingModel = 'per_person_night';
            $perNight = round($unitPrice * $occupancy, 2);
        }

        return [
            'occupancy' => $occupancy,
            'priceId' => (int) $price->getId(),
            'description' => $price->getDescription(),
            'pricingModel' => $pricingModel,
            'unitPrice' => $unitPrice,
            'perNight' => $perNight,
            'stayPrice' => 'flat' === $pricingModel ? $unitPrice : null,
            'minStay' => null !== $price->getMinStay() ? (int) $price->getMinStay() : null,
            'vat' => (float) $price->getVat(),
            'includesVat' => (bool) $price->getIncludesVat(),
            'isBookableOnline' => $price->getIsBookableOnline(),
        ];
    }
}
