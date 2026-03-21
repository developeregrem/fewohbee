<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\Reservation;
use App\Entity\RoomCategory;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * Calculate room prices for specific occupancy levels in the public booking flow.
 */
class PublicPricingService
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly OnlineBookingConfigService $configService,
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
}
