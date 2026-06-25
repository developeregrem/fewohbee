<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
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
     * When `guestCounts` is supplied (pattern from the public wizard) and its
     * occupancy-counted total matches the option's persons count, the option is priced with
     * the *actual* category mix — i.e. apartment-modifier deltas (children's discount etc.)
     * are reflected in the displayed step-2 price, so the guest sees the same number in
     * step 2 and step 3. Other occupancy options (1..maxGuests except the matching one) keep
     * the legacy adult-only fallback because the wizard hasn't asked for those mixes.
     *
     * Tourist tax is intentionally **not** applied here — it is shown as a separate line at
     * the end of the booking flow, not bundled into the room rate.
     *
     * @param array<int, int> $guestCounts        category-id => count from the wizard search step
     * @param int             $mixOccupancyPersons sum of occupancy-counted entries in $guestCounts;
     *                                             used to decide which occupancy option matches the
     *                                             user's mix and should reflect the modifier-aware
     *                                             price. The caller already knows this value
     *                                             (controller derives it from `isCountedInOccupancy`)
     *                                             — passing it here avoids re-injecting the
     *                                             GuestCategoryRepository into this service.
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
        array $guestCounts = [],
        int $mixOccupancyPersons = 0,
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

            // Apply the user's actual mix only on the option that matches the
            // occupancy-counted total of the mix. For other rows we keep the
            // adult-only baseline so the table still reflects "what would N
            // adults cost in this room".
            if ($mixOccupancyPersons > 0 && $mixOccupancyPersons === $persons && [] !== $guestCounts) {
                $reservation->setGuestCounts($guestCounts);
            }

            $positions = $this->invoiceService->buildAppartmentPositions($reservation);
            if ([] === $positions) {
                continue;
            }

            $modifierPositions = $this->invoiceService->buildApartmentModifierPositions([$reservation]);

            $vatSums = [];
            $brutto = 0.0;
            $netto = 0.0;
            $singleTotal = 0.0;
            $miscTotal = 0.0;
            $this->invoiceService->calculateSums(
                new ArrayCollection($positions),
                new ArrayCollection($modifierPositions),
                $vatSums,
                $brutto,
                $netto,
                $singleTotal,
                $miscTotal,
            );
            // Modifier deltas net into the room total (same scope as the
            // booking-journal routing: apartment_modifier groups with apartment).
            $singleTotal += $miscTotal;

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
     * Build the catalogue of bookable-online extras for the room-selection step (step 2),
     * spanning every available room category. Global extras (no room category) keep their
     * guest-selectable quantity; category-bound extras are flagged `autoQuantity` — their
     * quantity is derived from the actual booking later (see {@see resolveExtras()}) and is
     * therefore not user-editable. The concrete line totals are only known once rooms are
     * selected, so the catalogue intentionally exposes per-unit prices, not totals.
     *
     * @param array<int, array{categoryId: ?int, categoryName: ?string, sampleRoom: Appartment}> $samples
     *        One representative room per available type/category
     *
     * @return array<int, array{id: int, description: string, categoryId: ?int, categoryName: ?string, calculationType: string, unitPrice: float, unitPriceFormatted: string, pricePerUnit: float, pricePerUnitFormatted: string, maxQuantity: int, isMandatory: bool, autoQuantity: bool}>
     */
    public function catalogExtras(
        array $samples,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        int $totalPersons,
        int $totalRooms,
    ): array {
        $origin = $this->configService->getReservationOrigin();
        if (null === $origin || [] === $samples) {
            return [];
        }

        $nights = max(1, (int) $dateFrom->diff($dateTo)->days);
        $global = [];
        $byCategory = [];

        foreach ($samples as $sample) {
            $reservation = $this->buildSampleReservation($sample['sampleRoom'], max(1, $totalPersons), $dateFrom, $dateTo, $origin);
            foreach ($this->priceRepository->findBookableOnlineExtras($reservation) as $price) {
                $id = (int) $price->getId();
                $isGlobal = null === $price->getRoomCategory();
                if ($isGlobal && isset($global[$id])) {
                    continue;
                }
                if (!$isGlobal && isset($byCategory[$id])) {
                    continue;
                }

                $validDays = $this->countValidDays($reservation, $price, $nights);
                if (0 === $validDays && !$price->getIsFlatPrice()) {
                    continue;
                }

                $unitPrice = (float) $price->getPrice();
                [$calculationType, $perUnit] = $this->unitPricing($price, $unitPrice, $validDays, $isGlobal ? $totalPersons : 1);
                if ($perUnit <= 0.0) {
                    continue;
                }

                $entry = [
                    'id' => $id,
                    'description' => (string) $price->getDescription(),
                    'categoryId' => $price->getRoomCategory()?->getId(),
                    'categoryName' => $isGlobal ? null : ($sample['categoryName'] ?? $price->getRoomCategory()?->getName()),
                    'calculationType' => $calculationType,
                    'unitPrice' => $unitPrice,
                    'unitPriceFormatted' => number_format($unitPrice, 2, ',', '.'),
                    'pricePerUnit' => $perUnit,
                    'pricePerUnitFormatted' => number_format($perUnit, 2, ',', '.'),
                    'maxQuantity' => $isGlobal ? max(1, $totalRooms) : 1,
                    'isMandatory' => $price->getIsMandatoryOnline(),
                    'autoQuantity' => !$isGlobal,
                ];

                if ($isGlobal) {
                    $global[$id] = $entry;
                } else {
                    $byCategory[$id] = $entry;
                }
            }
        }

        // Global extras first, then category-bound grouped by category name.
        usort($byCategory, static fn ($a, $b) => [$a['categoryName'], $a['description']] <=> [$b['categoryName'], $b['description']]);

        return array_merge(array_values($global), array_values($byCategory));
    }

    /**
     * Resolve bookable-online extras against the concrete booked composition. Category-bound
     * extras receive a quantity derived from how many rooms / persons of that category are
     * actually booked (locked); global extras keep the guest-selected quantity. Mandatory
     * extras are forced on. Each entry carries its Price for downstream reservation attachment.
     *
     * @param array<int, array{categoryId: ?int, categoryName: ?string, sampleRoom: Appartment, roomCount: int, persons: int}> $buckets
     *        Booked rooms grouped by category (categoryId null = rooms without a category)
     * @param array<int, int> $selectedExtras Price ID => quantity/flag from the guest
     *
     * @return array<int, array{id: int, description: string, categoryId: ?int, categoryName: ?string, calculationType: string, isMandatory: bool, autoQuantity: bool, quantity: int, pricePerUnit: float, lineTotal: float, lineTotalFormatted: string, price: Price}>
     */
    public function resolveExtras(
        array $buckets,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        int $totalPersons,
        int $totalRooms,
        array $selectedExtras,
    ): array {
        $origin = $this->configService->getReservationOrigin();
        if (null === $origin || [] === $buckets) {
            return [];
        }

        $nights = max(1, (int) $dateFrom->diff($dateTo)->days);

        // Gather candidate prices, remembering which category bucket each belongs to.
        $candidates = [];
        foreach ($buckets as $bucket) {
            $reservation = $this->buildSampleReservation($bucket['sampleRoom'], max(1, (int) $bucket['persons']), $dateFrom, $dateTo, $origin);
            foreach ($this->priceRepository->findBookableOnlineExtras($reservation) as $price) {
                $id = (int) $price->getId();
                if (isset($candidates[$id])) {
                    continue;
                }
                $isGlobal = null === $price->getRoomCategory();
                $candidates[$id] = [
                    'price' => $price,
                    'reservation' => $reservation,
                    'bucket' => $isGlobal ? null : $bucket,
                ];
            }
        }

        $result = [];
        foreach ($candidates as $id => $candidate) {
            $price = $candidate['price'];
            $isGlobal = null === $price->getRoomCategory();
            $scopeRooms = $isGlobal ? $totalRooms : (int) $candidate['bucket']['roomCount'];
            $scopePersons = $isGlobal ? $totalPersons : (int) $candidate['bucket']['persons'];

            $validDays = $this->countValidDays($candidate['reservation'], $price, $nights);
            if (0 === $validDays && !$price->getIsFlatPrice()) {
                continue;
            }

            $unitPrice = (float) $price->getPrice();
            $mandatory = $price->getIsMandatoryOnline();
            $selected = (int) ($selectedExtras[$id] ?? 0);
            $isOn = $mandatory || $selected >= 1;

            if ($price->getIsFlatPrice()) {
                $calculationType = 'flat';
                $perUnit = $unitPrice;
                // Global: once per booking, guest-selectable. Category-bound: once per room of that
                // category (a flat price bills once per reservation, so N matching rooms => N×).
                $quantity = $isGlobal
                    ? min(max($mandatory ? 1 : $selected, 0), max(1, $scopeRooms))
                    : ($isOn ? $scopeRooms : 0);
            } elseif ($price->getIsPerRoom()) {
                $calculationType = 'per_room_night';
                $perUnit = $unitPrice * $validDays;
                $quantity = $isGlobal
                    ? min(max($mandatory ? 1 : $selected, 0), max(1, $scopeRooms))
                    : ($isOn ? $scopeRooms : 0);
            } else {
                $calculationType = 'per_person_night';
                // Single unit spans all persons in scope (mirrors legacy per-person behaviour).
                $perUnit = $unitPrice * $scopePersons * $validDays;
                $quantity = $isOn ? 1 : 0;
            }

            if ($quantity < 1 || $perUnit <= 0.0) {
                continue;
            }

            $lineTotal = $perUnit * $quantity;
            $result[] = [
                'id' => $id,
                'description' => (string) $price->getDescription(),
                'categoryId' => $price->getRoomCategory()?->getId(),
                'categoryName' => $price->getRoomCategory()?->getName(),
                'calculationType' => $calculationType,
                'isMandatory' => $mandatory,
                'autoQuantity' => !$isGlobal,
                'quantity' => $quantity,
                'pricePerUnit' => $perUnit,
                'lineTotal' => $lineTotal,
                'lineTotalFormatted' => number_format($lineTotal, 2, ',', '.'),
                'price' => $price,
            ];
        }

        return $result;
    }

    /**
     * Determine the calculation type label and per-unit price for an extra.
     *
     * @return array{0: string, 1: float} [calculationType, pricePerUnit]
     */
    private function unitPricing(Price $price, float $unitPrice, int $validDays, int $persons): array
    {
        if ($price->getIsFlatPrice()) {
            return ['flat', $unitPrice];
        }
        if ($price->getIsPerRoom()) {
            return ['per_room_night', $unitPrice * $validDays];
        }

        return ['per_person_night', $unitPrice * max(1, $persons) * $validDays];
    }

    /**
     * Count how many nights of the stay the extra is valid for (season/weekday aware).
     * Day 0 is the arrival day and is skipped (same as InvoiceService::prefillMiscPositions).
     */
    private function countValidDays(Reservation $reservation, Price $price, int $nights): int
    {
        $pricesPerDay = $this->priceService->getPricesForReservationDays(
            $reservation,
            1,
            new ArrayCollection([$price]),
        );

        $validDays = 0;
        for ($i = 1; $i <= $nights; ++$i) {
            if (isset($pricesPerDay[$i]) && null !== $pricesPerDay[$i]) {
                ++$validDays;
            }
        }

        return $validDays;
    }

    private function buildSampleReservation(Appartment $room, int $persons, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo, ReservationOrigin $origin): Reservation
    {
        $reservation = new Reservation();
        $reservation->setAppartment($room);
        $reservation->setStartDate(new \DateTime($dateFrom->format('Y-m-d')));
        $reservation->setEndDate(new \DateTime($dateTo->format('Y-m-d')));
        $reservation->setPersons($persons);
        $reservation->setReservationOrigin($origin);

        return $reservation;
    }
}
