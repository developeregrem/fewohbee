<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PublicBooking\RoomTotal;
use App\Entity\Appartment;
use App\Entity\Enum\ModifierType;
use App\Entity\GuestCategoryModifier;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\RoomCategory;
use App\Repository\GuestCategoryModifierRepository;
use App\Repository\GuestCategoryRepository;
use App\Repository\PriceRepository;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * Calculate room prices for specific occupancy levels in the public booking flow.
 */
class PublicPricingService
{
    /** Every adjustment on this room lowers the price. */
    public const ADJUSTMENT_REDUCTION = 'reduction';

    /** Every adjustment on this room raises it. */
    public const ADJUSTMENT_SURCHARGE = 'surcharge';

    /** Direction is not decidable before the guests are assigned to rooms. */
    public const ADJUSTMENT_MIXED = 'mixed';

    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly OnlineBookingConfigService $configService,
        private readonly PriceService $priceService,
        private readonly PriceRepository $priceRepository,
        private readonly GuestCategoryRepository $guestCategoryRepository,
        private readonly GuestCategoryModifierRepository $modifierRepository,
    ) {
    }

    /**
     * For a given room category, date range and max occupancy, compute the total stay price
     * for each valid number-of-persons (1..maxGuests) that has a matching price category.
     *
     * Every option is priced as the plain list price for that many guests. Per-guest
     * adjustments are deliberately left out: which room a discounted child ends up in
     * is only decided one step later, so pricing a row with the party's mix would be a
     * guess that changes the moment the guest picks differently. The wizard signals the
     * pending adjustment instead (see {@see describeGuestPriceAdjustment()}) and shows
     * the concrete amount as its own line in the summary.
     *
     * Tourist tax is intentionally **not** applied here — it is shown as a separate line at
     * the end of the booking flow, not bundled into the room rate.
     *
     * @return array<int, array{persons: int, totalPrice: float, totalPriceFormatted: string}>
     *         Indexed by persons count. Only entries with a non-zero price are returned.
     */
    public function getOccupancyPrices(
        // Not used for the calculation — kept so callers stay explicit about the
        // type they are pricing. Nullable because rooms without a category exist.
        ?RoomCategory $category,
        Appartment $sampleRoom,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        int $maxGuests,
    ): array {
        $origin = $this->configService->getReservationOrigin();
        $options = [];

        for ($persons = 1; $persons <= $maxGuests; ++$persons) {
            $reservation = $this->buildSampleReservation($sampleRoom, $persons, $dateFrom, $dateTo, $origin);

            $singleTotal = $this->calculateReservationRoomTotal($reservation)->total();
            if ($singleTotal <= 0.0) {
                continue;
            }

            $options[$persons] = [
                'persons' => $persons,
                'totalPrice' => $singleTotal,
                'totalPriceFormatted' => self::formatAmount($singleTotal),
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
                    'unitPriceFormatted' => self::formatAmount($unitPrice),
                    'pricePerUnit' => $perUnit,
                    'pricePerUnitFormatted' => self::formatAmount($perUnit),
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
                'lineTotalFormatted' => self::formatAmount($lineTotal),
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

    /**
     * Whether the party carries guests whose price differs from the plain per-head rate,
     * so the room list can say so before the concrete amount is known.
     *
     * The amount stays out of reach on purpose: it depends on which room each discounted
     * guest ends up in, and that is decided one step later. Everything else *is* knowable
     * here, because a modifier hangs off a guest category rather than off a room:
     *
     *  - a room priced flat or per room is never touched by modifiers → no hint at all;
     *  - `DISCOUNT_PERCENT` and `FREE` always reduce, `SURCHARGE_ABSOLUTE` always adds;
     *  - `FLAT_RATE` depends on this room's per-head rate — 14 € against a 30 € rate is a
     *    reduction, against a 12 € rate it is a surcharge;
     *  - with `minFullPayers` set, the first guests pay full fare regardless, so whether the
     *    adjustment applies at all depends on the allocation → the direction is left open.
     *
     * @param array<int, int> $guestCounts guest category id => count
     *
     * @return array{direction: string, labels: array<int, string>}|null null when nothing applies
     */
    public function describeGuestPriceAdjustment(
        Appartment $sampleRoom,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        array $guestCounts,
    ): ?array {
        if ([] === $guestCounts) {
            return null;
        }

        $basePerHead = $this->resolvePerHeadRate($sampleRoom, $dateFrom, $dateTo);
        if (null === $basePerHead) {
            return null;
        }

        $directions = [];
        $labels = [];
        foreach ($this->guestCategoryRepository->findActiveOrdered() as $category) {
            $count = (int) ($guestCounts[(int) $category->getId()] ?? 0);
            if ($count <= 0 || !$category->isCountedInOccupancy()) {
                continue;
            }

            $modifier = $this->modifierRepository->findApplicable($category, $dateFrom);
            if (null === $modifier) {
                continue;
            }

            $direction = $this->resolveAdjustmentDirection($modifier, $basePerHead, $sampleRoom);
            if (null === $direction) {
                continue;
            }

            $directions[$direction] = true;
            $labels[] = $category->getName();
        }

        if ([] === $labels) {
            return null;
        }

        return [
            'direction' => 1 === count($directions) ? array_key_first($directions) : self::ADJUSTMENT_MIXED,
            'labels' => $labels,
        ];
    }

    /**
     * The per-head rate this room is sold at, or null when the room is not priced per head.
     *
     * Flat and per-room rates are paid once for the whole room, so a per-guest modifier
     * has nothing to attach to — {@see InvoiceService::buildApartmentModifierPositions()}
     * skips them for exactly that reason.
     */
    private function resolvePerHeadRate(Appartment $room, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): ?float
    {
        $origin = $this->configService->getReservationOrigin();
        $reservation = $this->buildSampleReservation($room, 1, $dateFrom, $dateTo, $origin);

        foreach ($this->priceService->getPricesForReservationDays($reservation, 2) as $pricesOfDay) {
            $price = is_array($pricesOfDay) ? ($pricesOfDay[0] ?? null) : null;
            if (!$price instanceof Price) {
                continue;
            }
            if ($price->getIsFlatPrice() || $price->getIsPerRoom()) {
                return null;
            }

            return (float) $price->getPrice();
        }

        return null;
    }

    /** Which way a modifier moves this room's price, or null when it changes nothing. */
    private function resolveAdjustmentDirection(GuestCategoryModifier $modifier, float $basePerHead, Appartment $room): ?string
    {
        // The room type may reserve the first seats for full-fare guests. Whether the
        // adjustment survives that then depends on who shares the room — unknowable here.
        if (($room->getRoomCategory()?->getMinFullPayers() ?? 0) > 0) {
            return self::ADJUSTMENT_MIXED;
        }

        return match ($modifier->getType()) {
            ModifierType::DISCOUNT_PERCENT, ModifierType::FREE => self::ADJUSTMENT_REDUCTION,
            ModifierType::SURCHARGE_ABSOLUTE => self::ADJUSTMENT_SURCHARGE,
            ModifierType::FLAT_RATE => match (true) {
                $modifier->getValueAsFloat() < $basePerHead => self::ADJUSTMENT_REDUCTION,
                $modifier->getValueAsFloat() > $basePerHead => self::ADJUSTMENT_SURCHARGE,
                default => null,
            },
        };
    }

    /**
     * The one factory for the throw-away reservations the public pricing works on.
     *
     * Never persisted — it only carries enough state for the price engine to answer
     * "what would this room cost for this party in this period".
     *
     * @param array<int, int> $guestCounts guest category id => count, empty for the adult-only baseline
     */
    public function buildSampleReservation(
        Appartment $room,
        int $persons,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        ?ReservationOrigin $origin,
        array $guestCounts = [],
    ): Reservation {
        $reservation = new Reservation();
        $reservation->setAppartment($room);
        $reservation->setStartDate(new \DateTime($dateFrom->format('Y-m-d')));
        $reservation->setEndDate(new \DateTime($dateTo->format('Y-m-d')));
        $reservation->setPersons($persons);
        if (null !== $origin) {
            $reservation->setReservationOrigin($origin);
        }
        if ([] !== $guestCounts) {
            $reservation->setGuestCounts($guestCounts);
        }

        return $reservation;
    }

    /**
     * What one reservation costs — the single definition used by the price table,
     * the preview and the finished booking alike.
     *
     * Room rate and modifier deltas come back separately. Both belong to the same
     * scope the booking journal uses (apartment_modifier groups with apartment), so
     * {@see RoomTotal::total()} is the figure to bill; the split exists so the guest
     * can be shown *why* their total differs from the room's list price. A period
     * without an applicable price yields no positions and hence no modifiers either,
     * so the total is 0.0 and callers can treat that as "not bookable".
     */
    public function calculateReservationRoomTotal(Reservation $reservation): RoomTotal
    {
        $positions = $this->invoiceService->buildAppartmentPositions($reservation);
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

        return new RoomTotal($singleTotal, $miscTotal, $modifierPositions);
    }

    /**
     * Money as the public booking pages print it (German grouping).
     *
     * One definition so every total on the page reads the same; a locale-aware
     * format would replace this single call site.
     */
    public static function formatAmount(float $amount): string
    {
        return number_format($amount, 2, ',', '.');
    }
}
