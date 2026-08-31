<?php

declare(strict_types=1);

namespace App\Service\Api;

use App\Dto\Api\PriceQuoteDto;
use App\Entity\Appartment;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Repository\PriceRepository;
use App\Service\InvoiceService;
use App\Service\PublicPricingService;
use App\Service\TouristTaxService;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * Prices a hypothetical stay for the REST API.
 *
 * Deliberately owns no pricing rules of its own. Season priority, price periods, weekday
 * matching, minStay selection, guest-category modifiers and minFullPayers are all resolved
 * by PriceService/InvoiceService, and this class only assembles their output — the same
 * arrangement the statistics endpoints have with StatisticsService. Any rule reimplemented
 * here would be a rule that silently drifts away from the invoice.
 */
class PriceQuoteService
{
    public function __construct(
        private readonly PublicPricingService $pricingService,
        private readonly InvoiceService $invoiceService,
        private readonly PriceRepository $priceRepository,
        private readonly TouristTaxService $touristTaxService,
    ) {
    }

    /**
     * @param array<int, int> $guestCounts guest category id => head count
     * @param bool            $includeTouristTax false when the token may not see tourist tax
     */
    public function quote(
        Appartment $apartment,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        int $persons,
        array $guestCounts,
        ReservationOrigin $origin,
        bool $includeTouristTax,
    ): PriceQuoteDto {
        $nightCount = (int) $start->diff($end)->days;
        $reservation = $this->pricingService->buildSampleReservation(
            $apartment,
            $persons,
            $start,
            $end,
            $origin,
            $guestCounts,
        );

        $positions = $this->invoiceService->buildAppartmentPositions($reservation);
        $modifierPositions = $this->invoiceService->buildApartmentModifierPositions([$reservation]);

        $vats = [];
        $gross = 0.0;
        // calculateSums() reports the VAT total through its "$netto" out-parameter.
        $vatTotal = 0.0;
        $roomBase = 0.0;
        $modifierTotal = 0.0;
        $this->invoiceService->calculateSums(
            new ArrayCollection($positions),
            new ArrayCollection($modifierPositions),
            $vats,
            $gross,
            $vatTotal,
            $roomBase,
            $modifierTotal,
        );

        $vatRates = [];
        foreach ($vats as $rate => $values) {
            $vatRates[] = [
                'rate' => (float) $rate,
                'gross' => round((float) $values['brutto'], 2),
                'vat' => round((float) $values['netto'], 2),
                'net' => round((float) $values['netSum'], 2),
            ];
        }

        $extras = $this->buildExtras($reservation, $nightCount, $persons);
        $extrasTotal = 0.0;
        foreach ($extras as $extra) {
            if ($extra['isMandatoryOnline']) {
                $extrasTotal += $extra['total'];
            }
        }
        $extrasTotal = round($extrasTotal, 2);

        $touristTax = null;
        if ($includeTouristTax) {
            $touristTax = $this->buildTouristTax($reservation);
        }

        $category = $apartment->getRoomCategory();
        $object = $apartment->getObject();

        return new PriceQuoteDto(
            apartment: [
                'id' => $apartment->getId(),
                'number' => $apartment->getNumber(),
                'description' => $apartment->getDescription(),
            ],
            object: ['id' => $object?->getId(), 'name' => $object?->getName()],
            roomCategory: null !== $category ? ['id' => $category->getId(), 'name' => $category->getName()] : null,
            startDate: $start->format('Y-m-d'),
            endDate: $end->format('Y-m-d'),
            nightCount: $nightCount,
            persons: $persons,
            guestCounts: array_map('intval', $guestCounts),
            origin: ['id' => (int) $origin->getId(), 'name' => $origin->getName()],
            priceFound: [] !== $positions,
            room: [
                'gross' => round($gross, 2),
                'net' => round($gross - $vatTotal, 2),
                'vat' => round($vatTotal, 2),
                'base' => round($roomBase, 2),
                'modifiers' => round($modifierTotal, 2),
            ],
            vatRates: $vatRates,
            nights: $this->buildNightPeriods($positions),
            modifiers: $this->buildModifierLines($modifierPositions),
            extras: $extras,
            extrasTotal: $extrasTotal,
            touristTax: $touristTax,
            grandTotal: null === $touristTax
                ? null
                : round($gross + $extrasTotal + $touristTax['total'], 2),
        );
    }

    /**
     * The apartment positions as the invoice would print them: one entry per stretch of
     * nights sharing the same price row, so a stay crossing a season boundary comes back
     * as two entries rather than one averaged number.
     *
     * @param \App\Entity\InvoiceAppartment[] $positions
     *
     * @return list<array<string, mixed>>
     */
    private function buildNightPeriods(array $positions): array
    {
        $result = [];
        foreach ($positions as $position) {
            // getAmount() is the billed unit count (nights x persons for per-head rows,
            // 1 for flat rows) — not the night span, which the caller still needs.
            $nights = max(1, (int) $position->getStartDate()->diff($position->getEndDate())->days);
            $result[] = [
                'startDate' => $position->getStartDate()->format('Y-m-d'),
                'endDate' => $position->getEndDate()->format('Y-m-d'),
                'nights' => $nights,
                'description' => $position->getDescription(),
                'unitPrice' => round((float) $position->getPrice(), 2),
                'amount' => $position->getAmount(),
                'total' => round($position->getTotalPriceRaw(), 2),
                'vat' => $position->getVat(),
                'includesVat' => (bool) $position->getIncludesVat(),
                'isFlatPrice' => (bool) $position->getIsFlatPrice(),
                'isPerRoom' => $position->getIsPerRoom(),
                'persons' => $position->getPersons(),
            ];
        }

        return $result;
    }

    /**
     * Guest-category adjustments (child rates, extra-bed surcharges) as their own lines.
     * They are part of the room total, but shown separately so a consumer can explain
     * why the quote differs from the room's list price.
     *
     * @param \App\Entity\InvoicePosition[] $modifierPositions
     *
     * @return list<array<string, mixed>>
     */
    private function buildModifierLines(array $modifierPositions): array
    {
        $result = [];
        foreach ($modifierPositions as $position) {
            $result[] = [
                'description' => $position->getDescription(),
                'amount' => $position->getAmount(),
                'unitPrice' => round((float) $position->getPrice(), 2),
                'total' => round($position->getTotalPriceRaw(), 2),
                'vat' => $position->getVat(),
                'includesVat' => (bool) $position->getIncludesVat(),
            ];
        }

        return $result;
    }

    /**
     * Miscellaneous prices (type 1) that apply to this stay, priced for a single room.
     *
     * Only the ones flagged mandatory for online booking are summed into `extrasTotal` and
     * hence the grand total — a final cleaning fee belongs in the price a guest is quoted,
     * an optional breakfast does not. Everything applicable is listed either way, with its
     * flags, so a caller can build its own basket.
     *
     * @return list<array<string, mixed>>
     */
    private function buildExtras(Reservation $reservation, int $nightCount, int $persons): array
    {
        $result = [];
        foreach ($this->priceRepository->findMiscPrices($reservation) as $price) {
            $validDays = $this->pricingService->countValidDays($reservation, $price, $nightCount);
            if (0 === $validDays && !$price->getIsFlatPrice()) {
                continue;
            }

            [$calculationType, $total] = $this->pricingService->unitPricing(
                $price,
                (float) $price->getPrice(),
                $validDays,
                $persons,
            );

            $result[] = [
                'id' => (int) $price->getId(),
                'description' => $price->getDescription(),
                'calculationType' => $calculationType,
                'unitPrice' => round((float) $price->getPrice(), 2),
                'validNights' => $validDays,
                'total' => round($total, 2),
                'vat' => (float) $price->getVat(),
                'includesVat' => (bool) $price->getIncludesVat(),
                'isBookableOnline' => $price->getIsBookableOnline(),
                'isMandatoryOnline' => $price->getIsMandatoryOnline(),
                'isDefaultActiveInReservationCreation' => $price->getIsDefaultActiveInReservationCreation(),
                'roomCategory' => null !== $price->getRoomCategory()
                    ? ['id' => $price->getRoomCategory()->getId(), 'name' => $price->getRoomCategory()->getName()]
                    : null,
            ];
        }

        return $result;
    }

    /**
     * @return array{total: float, items: list<array<string, mixed>>}
     */
    private function buildTouristTax(Reservation $reservation): array
    {
        $items = [];
        $total = 0.0;
        foreach ($this->touristTaxService->calculateForReservation($reservation) as $row) {
            $amount = round($row->total(), 2);
            $total += $amount;
            $items[] = [
                'taxId' => $row->taxId,
                'taxName' => $row->taxName,
                'calculationMode' => $row->calculationMode->value,
                'guestCategory' => 0 !== $row->categoryId
                    ? ['id' => $row->categoryId, 'name' => $row->categoryName]
                    : null,
                'reportGroup' => $row->reportGroup,
                'pricePerNight' => round($row->pricePerNight, 2),
                'nights' => $row->nights,
                'count' => $row->count,
                'percentageRate' => $row->percentageRate,
                'apartmentBase' => $row->apartmentBase,
                'includesVat' => $row->includesVat,
                'vatRate' => $row->taxRate?->getRateFloat(),
                'total' => $amount,
            ];
        }

        return ['total' => round($total, 2), 'items' => $items];
    }
}
