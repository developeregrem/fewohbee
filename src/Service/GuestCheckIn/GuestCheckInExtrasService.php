<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use App\Entity\Price;
use App\Entity\Reservation;
use App\Repository\PriceRepository;
use App\Service\PriceService;
use Doctrine\Common\Collections\ArrayCollection;

/** Optional whole-stay services for one existing reservation, using its own origin and prices. */
class GuestCheckInExtrasService
{
    public function __construct(
        private readonly PriceRepository $prices,
        private readonly PriceService $priceService,
    ) {
    }

    /**
     * A price can be attached only once to a reservation. Quantity and individual days are not
     * offered here, because Reservation::prices cannot persist either choice.
     *
     * @return list<array{id: int, description: string, total: string, totalFormatted: string, booked: bool, price: Price}>
     */
    public function available(Reservation $reservation): array
    {
        if (null === $reservation->getReservationOrigin() || null === $reservation->getAppartment()) {
            return [];
        }

        $nights = (int) $reservation->getStartDate()->diff($reservation->getEndDate())->days;
        if ($nights < 1) {
            return [];
        }

        $result = [];
        foreach ($this->prices->findBookableOnlineExtras($reservation) as $price) {
            // "Mandatory in online booking" only governs that booking flow. Imported and manual
            // reservations must not be charged automatically during check-in.
            $booked = $reservation->getPrices()->contains($price);
            if ($price->getIsMandatoryOnline() && !$booked) {
                continue;
            }

            $days = $this->priceService->getPricesForReservationDays($reservation, 1, new ArrayCollection([$price]));
            $validDays = 0;
            for ($day = 1; $day <= $nights; ++$day) {
                if (\in_array($price, $days[$day] ?? [], true)) {
                    ++$validDays;
                }
            }
            if (0 === $validDays && !$price->getIsFlatPrice()) {
                continue;
            }

            $total = (float) $price->getPrice();
            if (!$price->getIsFlatPrice()) {
                $total *= $validDays * ($price->getIsPerRoom() ? 1 : max(1, $reservation->getPersons()));
            }
            if ($total <= 0) {
                continue;
            }

            $result[] = [
                'id' => (int) $price->getId(),
                'description' => (string) $price->getDescription(),
                'total' => number_format($total, 2, '.', ''),
                'totalFormatted' => number_format($total, 2, ',', '.'),
                'booked' => $booked,
                'price' => $price,
            ];
        }

        return $result;
    }

    /**
     * Record what the guest saw at submission time, so a later price change cannot silently
     * create a charge at a different amount when the hotelier takes the data over.
     *
     * @param list<int> $selectedIds
     *
     * @return list<array{id: int, description: string, total: string}>
     */
    public function snapshot(Reservation $reservation, array $selectedIds): array
    {
        $available = [];
        foreach ($this->available($reservation) as $extra) {
            if (!$extra['booked']) {
                $available[$extra['id']] = $extra;
            }
        }

        $selected = [];
        foreach ($selectedIds as $id) {
            if (isset($selected[$id]) || !isset($available[$id])) {
                throw new \InvalidArgumentException('Unknown or already booked service.');
            }
            $extra = $available[$id];
            $selected[$id] = [
                'id' => $id,
                'description' => $extra['description'],
                'total' => $extra['total'],
            ];
        }

        return array_values($selected);
    }

    /**
     * Validate the guest's selections before applying any other submitted data. A price that
     * disappeared or changed requires the hotelier to resolve the request manually.
     *
     * @param array<mixed> $requested
     *
     * @return list<Price>
     */
    public function pricesToApply(Reservation $reservation, array $requested): array
    {
        $available = [];
        foreach ($this->available($reservation) as $extra) {
            $available[$extra['id']] = $extra;
        }

        $result = [];
        foreach ($requested as $item) {
            if (!\is_array($item) || !\is_int($item['id'] ?? null) || !\is_string($item['total'] ?? null)) {
                throw new \InvalidArgumentException('Invalid service request.');
            }
            $id = $item['id'];
            if (isset($result[$id])) {
                throw new \InvalidArgumentException('Duplicate service request.');
            }
            if ($reservation->getPrices()->exists(static fn (int $key, Price $price): bool => $price->getId() === $id)) {
                continue;
            }
            $extra = $available[$id] ?? null;
            if (null === $extra || $extra['total'] !== $item['total']) {
                throw new GuestCheckInExtrasConflictException('Service price or availability changed.');
            }
            $result[$id] = $extra['price'];
        }

        return array_values($result);
    }
}
