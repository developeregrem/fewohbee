<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\Reservation;
use App\Entity\RoomCategory;
use App\Repository\PriceRepository;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * Calculate room prices for specific occupancy levels in the public booking flow.
 */
class PublicPricingService
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly OnlineBookingConfigService $configService,
        private readonly PriceService $priceService,
        private readonly PriceRepository $priceRepository,
    ) {
    }

    /**
     * For a given room category, date range and max occupancy, compute the total stay price
     * for each valid number-of-persons (1..maxGuests) that has a matching price category.
     *
     * @return array<int, array{persons: int, totalPrice: float, totalPriceFormatted: string}>
     *         Indexed by persons count. Only entries with a non-zero price are returned.
     */
    public function getOccupancyPrices(
        RoomCategory $category,
        Appartment $sampleRoom,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        int $maxGuests,
    ): array {
        $origin = $this->configService->getReservationOrigin();
        $options = [];

        for ($persons = 1; $persons <= $maxGuests; ++$persons) {
            $reservation = new Reservation();
            $reservation->setAppartment($sampleRoom);
            $reservation->setStartDate(new \DateTime($dateFrom->format('Y-m-d')));
            $reservation->setEndDate(new \DateTime($dateTo->format('Y-m-d')));
            $reservation->setPersons($persons);
            if (null !== $origin) {
                $reservation->setReservationOrigin($origin);
            }

            $positions = $this->invoiceService->buildAppartmentPositions($reservation);
            if ([] === $positions) {
                continue;
            }

            $vatSums = [];
            $brutto = 0.0;
            $netto = 0.0;
            $singleTotal = 0.0;
            $miscTotal = 0.0;
            $this->invoiceService->calculateSums(
                new ArrayCollection($positions),
                new ArrayCollection(),
                $vatSums,
                $brutto,
                $netto,
                $singleTotal,
                $miscTotal,
            );

            if ($singleTotal <= 0.0) {
                continue;
            }

            $options[$persons] = [
                'persons' => $persons,
                'totalPrice' => $singleTotal,
                'totalPriceFormatted' => number_format($singleTotal, 2, ',', '.'),
            ];
        }

        return $options;
    }

    /**
     * Retrieve all bookable-online extras with calculated total prices for the given stay.
     *
     * @return array<int, array{id: int, description: string, unitPrice: float, unitPriceFormatted: string, calculationType: string, pricePerUnit: float, pricePerUnitFormatted: string, maxQuantity: int}>
     */
    public function getBookableExtras(
        Appartment $sampleRoom,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        int $persons,
        int $roomsCount,
    ): array {
        $origin = $this->configService->getReservationOrigin();
        if (null === $origin) {
            return [];
        }

        $reservation = new Reservation();
        $reservation->setAppartment($sampleRoom);
        $reservation->setStartDate(new \DateTime($dateFrom->format('Y-m-d')));
        $reservation->setEndDate(new \DateTime($dateTo->format('Y-m-d')));
        $reservation->setPersons($persons);
        $reservation->setReservationOrigin($origin);

        $extras = $this->priceRepository->findBookableOnlineExtras($reservation);
        if ([] === $extras) {
            return [];
        }

        $nights = max(1, (int) $dateFrom->diff($dateTo)->days);
        $result = [];

        foreach ($extras as $price) {
            // Check that this price has valid days in the stay period
            $pricesPerDay = $this->priceService->getPricesForReservationDays(
                $reservation,
                1,
                new ArrayCollection([$price]),
            );

            $validDays = 0;
            // Day 0 is arrival day, skip it — count from day 1 (same as InvoiceService::prefillMiscPositions)
            for ($i = 1; $i <= $nights; ++$i) {
                if (isset($pricesPerDay[$i]) && null !== $pricesPerDay[$i]) {
                    ++$validDays;
                }
            }

            if (0 === $validDays && !$price->getIsFlatPrice()) {
                continue;
            }

            $unitPrice = (float) $price->getPrice();
            $isFlatPrice = $price->getIsFlatPrice();
            $isPerRoom = $price->getIsPerRoom();

            if ($isFlatPrice) {
                $calculationType = 'flat';
                $pricePerUnit = $unitPrice;
                $maxQuantity = $roomsCount;
            } elseif ($isPerRoom) {
                $calculationType = 'per_room_night';
                $pricePerUnit = $unitPrice * $validDays;
                $maxQuantity = $roomsCount;
            } else {
                $calculationType = 'per_person_night';
                $pricePerUnit = $unitPrice * $persons * $validDays;
                $maxQuantity = 1;
            }

            if ($pricePerUnit <= 0.0) {
                continue;
            }

            $result[] = [
                'id' => (int) $price->getId(),
                'description' => (string) $price->getDescription(),
                'unitPrice' => $unitPrice,
                'unitPriceFormatted' => number_format($unitPrice, 2, ',', '.'),
                'calculationType' => $calculationType,
                'pricePerUnit' => $pricePerUnit,
                'pricePerUnitFormatted' => number_format($pricePerUnit, 2, ',', '.'),
                'maxQuantity' => $maxQuantity,
            ];
        }

        return $result;
    }
}
