<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\OnlineBookingConfig;
use App\Entity\Price;
use App\Exception\PublicBookingException;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Repository\AppartmentRepository;
use App\Repository\CustomerRepository;
use App\Repository\GuestCategoryRepository;
use App\Event\OnlineBookingCreatedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class PublicBookingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppartmentRepository $appartmentRepository,
        private readonly OnlineBookingConfigService $configService,
        private readonly PublicAvailabilityService $availabilityService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly PublicPricingService $pricingService,
        private readonly ?GuestCategoryRepository $guestCategoryRepository = null,
        private readonly ?TouristTaxService $touristTaxService = null,
    ) {
    }

    /**
     * Validate public input and return preview data (availability + room total).
     *
     * @param array<string, array<int, int>> $occupancySelection e.g. ['category:1' => [2 => 1, 1 => 0]]
     * @param array<int, int> $selectedExtras Map of Price ID => quantity
     * @param array<int, int> $guestCounts Map of guest category ID => count
     * @return array{availability: array<int, array<string, mixed>>, selected: array<string,array<int,int>>, roomTotal: float, roomTotalFormatted: string, roomPriceBreakdown: array<int, array{label: string, quantity: int, total: float, totalFormatted: string}>, modifierTotal: float, modifierBreakdown: array<int, array{label: string, total: float, totalFormatted: string}>, roomReservations: Reservation[], touristTaxTotal: float, touristTaxTotalFormatted: string, touristTaxLines: array<int, array{label: string, total: float, totalFormatted: string}>, extras: array<int, array<string, mixed>>, selectedExtras: array<int,int>, extrasTotal: float, extrasTotalFormatted: string, extrasBreakdown: array<int, array{label: string, quantity: int, total: float, totalFormatted: string}>, grandTotal: float, grandTotalFormatted: string}
     */
    public function buildSelectionPreview(
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        int $persons,
        int $roomsCount,
        array $occupancySelection,
        array $selectedExtras = [],
        array $guestCounts = [],
        ?Appartment $calendarRoom = null,
    ): array {
        $config = $this->configService->getConfig();
        $availability = $this->resolveAvailability($calendarRoom, $dateFrom, $dateTo, $persons, $roomsCount, $config, $guestCounts);

        $selection = $this->normalizeOccupancySelection($occupancySelection);
        if ([] === $selection && [] !== $occupancySelection) {
            throw new PublicBookingException('online_booking.error.select_at_least_one_room');
        }

        if ([] !== $selection) {
            $this->validateOccupancySelectionAgainstAvailability($selection, $availability, $persons, $roomsCount);
        }
        $assignedRoomsWithPersons = $this->assignRoomsWithOccupancy($availability, $selection);
        $roomReservations = $this->buildTransientReservationsWithExplicitPersons($assignedRoomsWithPersons, $dateFrom, $dateTo);
        $this->distributeGuestCounts($roomReservations, $guestCounts);
        $pricing = $this->calculateRoomTotal($roomReservations);

        // Extras catalogue for the selection UI (spans every available category).
        $extras = $this->loadBookableExtras($availability, $dateFrom, $dateTo, $persons, $roomsCount);
        // Resolve concrete quantities/totals from the chosen rooms (empty until a room is selected).
        $composition = $this->buildExtrasComposition($assignedRoomsWithPersons);
        $resolvedExtras = $this->pricingService->resolveExtras(
            $composition['buckets'],
            $dateFrom,
            $dateTo,
            $composition['totalPersons'],
            $composition['totalRooms'],
            $selectedExtras,
        );
        $extrasResult = $this->summarizeExtras($resolvedExtras);

        // Per-guest adjustments are a separate line now, so they have to be added back
        // here — the room total is the list price of the rooms alone.
        $grandTotal = $pricing['roomTotal'] + $pricing['modifierTotal'] + $extrasResult['extrasTotal'] + $pricing['touristTaxTotal'];

        return [
            'availability' => $availability,
            'selected' => $selection,
            'roomTotal' => $pricing['roomTotal'],
            'roomTotalFormatted' => $pricing['roomTotalFormatted'],
            'roomPriceBreakdown' => $pricing['roomPriceBreakdown'],
            'modifierTotal' => $pricing['modifierTotal'],
            'modifierBreakdown' => $pricing['modifierBreakdown'],
            'roomReservations' => $roomReservations,
            'touristTaxTotal' => $pricing['touristTaxTotal'],
            'touristTaxTotalFormatted' => $pricing['touristTaxTotalFormatted'],
            'touristTaxLines' => $pricing['touristTaxLines'],
            'extras' => $extras,
            'selectedExtras' => $selectedExtras,
            'extrasTotal' => $extrasResult['extrasTotal'],
            'extrasTotalFormatted' => $extrasResult['extrasTotalFormatted'],
            'extrasBreakdown' => $extrasResult['extrasBreakdown'],
            'grandTotal' => $grandTotal,
            'grandTotalFormatted' => PublicPricingService::formatAmount($grandTotal),
        ];
    }

    /**
     * Create reservations for a public booking request.
     *
     * @param array<string, array<int, int>> $occupancySelection e.g. ['category:1' => [2 => 1]]
     * @param array<string, string> $booker
     * @param array<int, int> $selectedExtras Map of Price ID => quantity
     * @param array<int, int> $guestCounts Map of guest category ID => count
     * @return array{reservations: Reservation[], bookingGroupUuid: Uuid, roomTotal: float, roomTotalFormatted: string, roomPriceBreakdown: array<int, array{label: string, quantity: int, total: float, totalFormatted: string}>, modifierTotal: float, modifierBreakdown: array<int, array{label: string, total: float, totalFormatted: string}>, touristTaxTotal: float, touristTaxTotalFormatted: string, extrasTotal: float, extrasTotalFormatted: string, grandTotal: float, grandTotalFormatted: string}
     */
    public function createBooking(
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        int $persons,
        int $roomsCount,
        array $occupancySelection,
        array $booker,
        array $selectedExtras = [],
        array $guestCounts = [],
        ?Appartment $calendarRoom = null,
    ): array {
        $config = $this->configService->getConfig();
        $this->assertConfigReady($config);

        $selection = $this->normalizeOccupancySelection($occupancySelection);
        $availability = $this->resolveAvailability($calendarRoom, $dateFrom, $dateTo, $persons, $roomsCount, $config, $guestCounts);
        $this->validateOccupancySelectionAgainstAvailability($selection, $availability, $persons, $roomsCount);

        $assignedRoomsWithPersons = $this->assignRoomsWithOccupancy($availability, $selection);
        $reservations = $this->buildTransientReservationsWithExplicitPersons($assignedRoomsWithPersons, $dateFrom, $dateTo);
        $this->distributeGuestCounts($reservations, $guestCounts);

        // Resolve selected extras against the concrete booked composition. Category-bound extras
        // get their quantity from the booked rooms of that category; mandatory ones are forced on.
        $composition = $this->buildExtrasComposition($assignedRoomsWithPersons);
        $resolvedExtras = $this->pricingService->resolveExtras(
            $composition['buckets'],
            $dateFrom,
            $dateTo,
            $composition['totalPersons'],
            $composition['totalRooms'],
            $selectedExtras,
        );

        $status = OnlineBookingConfig::BOOKING_MODE_BOOKING === $config->getBookingMode()
            ? $this->configService->getBookingStatus($config)
            : $this->configService->getInquiryStatus($config);
        if (!$status instanceof ReservationStatus) {
            throw new PublicBookingException('online_booking.error.invalid_status_config');
        }

        $origin = $this->configService->getReservationOrigin($config);
        if (null === $origin) {
            throw new PublicBookingException('online_booking.error.reservation_origin_missing');
        }

        $customer = $this->findOrCreateBookerCustomer(
            $booker
        );
        $publicComment = self::sanitize($booker['comment'] ?? '', 2000);

        $bookingGroupUuid = Uuid::v4();
        foreach ($reservations as $reservation) {
            $reservation->setReservationOrigin($origin);
            $reservation->setReservationStatus($status);
            $reservation->setBooker($customer);
            $reservation->setUuid(Uuid::v4());
            $reservation->setBookingGroupUuid($bookingGroupUuid);
            if ('' !== $publicComment) {
                $reservation->setRemark($publicComment);
            }
        }

        // Attach extras to the matching reservations (category-aware, calc-type-aware).
        $this->attachExtrasToReservations($reservations, $resolvedExtras);

        foreach ($reservations as $reservation) {
            $this->em->persist($reservation);
        }
        $this->em->flush();

        $pricing = $this->calculateRoomTotal($reservations);
        $extrasResult = $this->summarizeExtras($resolvedExtras);

        $this->eventDispatcher->dispatch(new OnlineBookingCreatedEvent($reservations, $customer));

        // Grand total must match the preview: room rates + guest adjustments + extras + tourist tax.
        $grandTotal = $pricing['roomTotal'] + $pricing['modifierTotal'] + $extrasResult['extrasTotal'] + $pricing['touristTaxTotal'];

        return [
            'reservations' => $reservations,
            'bookingGroupUuid' => $bookingGroupUuid,
            'roomTotal' => $pricing['roomTotal'],
            'roomTotalFormatted' => $pricing['roomTotalFormatted'],
            'roomPriceBreakdown' => $pricing['roomPriceBreakdown'],
            'modifierTotal' => $pricing['modifierTotal'],
            'modifierBreakdown' => $pricing['modifierBreakdown'],
            'touristTaxTotal' => $pricing['touristTaxTotal'],
            'touristTaxTotalFormatted' => $pricing['touristTaxTotalFormatted'],
            'extrasTotal' => $extrasResult['extrasTotal'],
            'extrasTotalFormatted' => $extrasResult['extrasTotalFormatted'],
            'grandTotal' => $grandTotal,
            'grandTotalFormatted' => PublicPricingService::formatAmount($grandTotal),
        ];
    }

    /** Check whether the current online booking config is enabled and structurally valid for runtime use. */
    public function validateEnabledConfig(): ?string
    {
        $config = $this->configService->getConfig();

        if (!$config->isEnabled()) {
            return 'online_booking.error.disabled';
        }

        try {
            $this->assertConfigReady($config);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Offer rows for the current entry point.
     *
     * The calendar path already knows the room, so it asks for that one room only;
     * the search path runs the full availability query. Both return the same row
     * shape, which is why everything downstream of this call is mode-agnostic.
     *
     * @param array<int, int> $guestCounts
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveAvailability(
        ?Appartment $calendarRoom,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        int $persons,
        int $roomsCount,
        OnlineBookingConfig $config,
        array $guestCounts,
    ): array {
        if ($calendarRoom instanceof Appartment) {
            return $this->availabilityService->getAvailabilityForRoom($calendarRoom, $dateFrom, $dateTo, $guestCounts);
        }

        return $this->availabilityService->getAvailability($dateFrom, $dateTo, $persons, $roomsCount, $config, $guestCounts);
    }

    /** Guard booking execution against incomplete or invalid online booking configuration. */
    private function assertConfigReady(OnlineBookingConfig $config): void
    {
        if (!$config->isEnabled()) {
            throw new PublicBookingException('online_booking.error.disabled');
        }

        if (null === $this->configService->getReservationOrigin($config)) {
            throw new PublicBookingException('online_booking.error.reservation_origin_missing');
        }

        if (!$this->configService->getInquiryStatus($config) instanceof ReservationStatus) {
            throw new PublicBookingException('online_booking.error.invalid_status_config');
        }
        if (!$this->configService->getBookingStatus($config) instanceof ReservationStatus) {
            throw new PublicBookingException('online_booking.error.invalid_status_config');
        }
    }

    /**
     * Normalize occupancy-based selection: keep only positive quantities.
     *
     * @param array<string, array<int, int>> $occupancySelection e.g. ['category:1' => [2 => 1, 1 => 0]]
     * @return array<string, array<int, int>> Only entries with qty > 0
     */
    private function normalizeOccupancySelection(array $occupancySelection): array
    {
        $normalized = [];
        foreach ($occupancySelection as $typeKey => $personQtyMap) {
            if (!is_array($personQtyMap)) {
                continue;
            }
            $filtered = [];
            foreach ($personQtyMap as $persons => $qty) {
                $personsInt = max(0, (int) $persons);
                $qtyInt = max(0, (int) $qty);
                if ($qtyInt > 0 && $personsInt > 0) {
                    $filtered[$personsInt] = $qtyInt;
                }
            }
            if ([] !== $filtered) {
                ksort($filtered);
                $normalized[(string) $typeKey] = $filtered;
            }
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * Map occupancy selection to concrete rooms with explicit person counts.
     *
     * @param array<int, array{
     *   typeKey: string,
     *   typeLabel: string,
     *   maxGuests: int,
     *   availableCount: int,
     *   roomIds: int[],
     *   roomCapacities?: array<int, int>
     * }> $availability
     * @param array<string, array<int, int>> $selection
     * @return array<int, array{room: Appartment, persons: int}>
     */
    private function assignRoomsWithOccupancy(array $availability, array $selection): array
    {
        /** @var array<int, int> $personsByRoomId */
        $personsByRoomId = [];

        foreach ($availability as $row) {
            $typeKey = $row['typeKey'];
            $personQtyMap = $selection[$typeKey] ?? [];
            if ([] === $personQtyMap) {
                continue;
            }

            $totalQtyForType = array_sum($personQtyMap);
            if ($totalQtyForType > (int) $row['availableCount']) {
                throw new PublicBookingException('online_booking.error.qty_exceeds_availability');
            }

            $roomCapacities = [];
            foreach ($row['roomIds'] as $roomId) {
                $roomCapacities[(int) $roomId] = (int) ($row['roomCapacities'][$roomId] ?? $row['maxGuests']);
            }

            asort($roomCapacities);

            $requestedOccupancies = [];
            foreach ($personQtyMap as $persons => $qty) {
                for ($i = 0; $i < $qty; ++$i) {
                    $requestedOccupancies[] = (int) $persons;
                }
            }

            rsort($requestedOccupancies);

            foreach ($requestedOccupancies as $persons) {
                $assignedRoomId = null;
                foreach ($roomCapacities as $roomId => $capacity) {
                    if ($capacity >= $persons) {
                        $assignedRoomId = (int) $roomId;
                        break;
                    }
                }

                if (null === $assignedRoomId) {
                    throw new PublicBookingException('online_booking.error.insufficient_capacity');
                }

                $personsByRoomId[$assignedRoomId] = $persons;
                unset($roomCapacities[$assignedRoomId]);
            }
        }

        if ([] === $personsByRoomId) {
            return [];
        }

        $assignedRoomIds = array_keys($personsByRoomId);
        $rooms = $this->appartmentRepository->findByIdsWithRelations($assignedRoomIds);
        $byId = [];
        foreach ($rooms as $room) {
            $byId[(int) $room->getId()] = $room;
        }

        $result = [];
        foreach ($assignedRoomIds as $roomId) {
            if (isset($byId[$roomId])) {
                $result[] = [
                    'room' => $byId[$roomId],
                    'persons' => $personsByRoomId[$roomId],
                ];
            }
        }

        return $result;
    }

    /**
     * Validate occupancy-based selection against freshly computed availability.
     *
     * @param array<string, array<int, int>> $selection
     * @param array<int, array{
     *   typeKey: string,
     *   maxGuests: int,
     *   availableCount: int,
     *   occupancyOptions: array,
     *   occupancyAvailableCounts?: array<int, int>
     * }> $availability
     */
    private function validateOccupancySelectionAgainstAvailability(array $selection, array $availability, int $persons, int $roomsCount): void
    {
        $totalRooms = 0;
        $totalPersons = 0;

        $availabilityMap = [];
        foreach ($availability as $row) {
            $availabilityMap[$row['typeKey']] = $row;
        }

        foreach ($selection as $typeKey => $personQtyMap) {
            if (!isset($availabilityMap[$typeKey])) {
                throw new PublicBookingException('online_booking.error.room_type_no_longer_available');
            }
            $row = $availabilityMap[$typeKey];
            $typeQty = 0;

            foreach ($personQtyMap as $personsCount => $qty) {
                if ($personsCount > (int) $row['maxGuests']) {
                    throw new PublicBookingException('online_booking.error.insufficient_capacity');
                }
                if (!$this->hasOccupancyOptionForPersons($row['occupancyOptions'], $personsCount)) {
                    throw new PublicBookingException('online_booking.error.occupancy_no_price');
                }
                if ($qty > (int) ($row['occupancyAvailableCounts'][$personsCount] ?? $row['availableCount'])) {
                    throw new PublicBookingException('online_booking.error.qty_exceeds_availability');
                }
                $typeQty += $qty;
                $totalPersons += $qty * $personsCount;
            }

            if ($typeQty > (int) $row['availableCount']) {
                throw new PublicBookingException('online_booking.error.qty_exceeds_availability');
            }
            $totalRooms += $typeQty;
        }

        if ($totalRooms !== $roomsCount) {
            throw new PublicBookingException('online_booking.error.qty_sum_mismatch');
        }

        if ($totalRooms < 1) {
            throw new PublicBookingException('online_booking.error.select_at_least_one_room');
        }

        if ($totalPersons !== $persons) {
            throw new PublicBookingException('online_booking.error.persons_sum_mismatch');
        }
    }

    /**
     * Availability rows may expose occupancy options either keyed by persons count or as a flat list.
     *
     * @param array<int|string, array{persons?: int}> $occupancyOptions
     */
    private function hasOccupancyOptionForPersons(array $occupancyOptions, int $personsCount): bool
    {
        if (isset($occupancyOptions[$personsCount]) && (int) ($occupancyOptions[$personsCount]['persons'] ?? 0) === $personsCount) {
            return true;
        }

        foreach ($occupancyOptions as $option) {
            if ((int) ($option['persons'] ?? 0) === $personsCount) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build transient reservations with explicit person counts (no auto-distribution).
     *
     * @param array<int, array{room: Appartment, persons: int}> $assignedRoomsWithPersons
     * @return Reservation[]
     */
    private function buildTransientReservationsWithExplicitPersons(array $assignedRoomsWithPersons, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        if ([] === $assignedRoomsWithPersons) {
            return [];
        }

        $origin = $this->configService->getReservationOrigin();
        $reservations = [];

        foreach ($assignedRoomsWithPersons as $entry) {
            $room = $entry['room'];
            $persons = $entry['persons'];

            if ($persons < 1 || $persons > (int) $room->getBedsMax()) {
                throw new PublicBookingException('online_booking.error.guest_distribution_invalid');
            }

            $reservation = new Reservation();
            $reservation->setAppartment($room);
            $reservation->setStartDate(new \DateTime($dateFrom->format('Y-m-d')));
            $reservation->setEndDate(new \DateTime($dateTo->format('Y-m-d')));
            $reservation->setPersons($persons);
            if (null !== $origin) {
                $reservation->setReservationOrigin($origin);
            }

            $reservations[] = $reservation;
        }

        return $reservations;
    }

    /**
     * Distribute the wizard's per-category counts onto the (already
     * persons-distributed) transient reservations.
     *
     * Single-room: full guestCounts go to the only reservation.
     * Multi-room: greedy distribution — guarantees ≥1 adult per room (when
     * adults available), then fills each room up to its `persons` capacity
     * by walking through ADULT, then non-ADULT occupancy categories. Any
     * non-occupancy categories (e.g. infants) are attached to the first
     * reservation. This is a heuristic, not a user-controlled split.
     *
     * @param Reservation[]    $reservations
     * @param array<int, int>  $guestCounts category-id => count
     */
    private function distributeGuestCounts(array $reservations, array $guestCounts): void
    {
        if ([] === $reservations || [] === $guestCounts || null === $this->guestCategoryRepository) {
            return;
        }

        $categories = [];
        foreach ($this->guestCategoryRepository->findAll() as $gc) {
            $categories[$gc->getId()] = $gc;
        }

        // Single-room shortcut: full counts on the single reservation.
        if (1 === count($reservations)) {
            $reservations[0]->setGuestCounts($guestCounts);

            return;
        }

        // Multi-room: build buckets by occupancy semantics.
        $adultIds = [];
        $otherOccupancyIds = [];
        $nonOccupancyIds = [];
        foreach ($guestCounts as $catId => $count) {
            $cat = $categories[$catId] ?? null;
            if (null === $cat || $count <= 0) {
                continue;
            }
            if (!$cat->isCountedInOccupancy()) {
                $nonOccupancyIds[] = $catId;
                continue;
            }
            if ($cat->isAdult()) {
                $adultIds[] = $catId;
            } else {
                $otherOccupancyIds[] = $catId;
            }
        }

        $remaining = $guestCounts;
        $perRoomCounts = array_fill(0, count($reservations), []);

        // Pass 1: guarantee 1 adult per room (when adults available).
        foreach ($reservations as $idx => $_) {
            foreach ($adultIds as $catId) {
                if (($remaining[$catId] ?? 0) > 0) {
                    $perRoomCounts[$idx][$catId] = ($perRoomCounts[$idx][$catId] ?? 0) + 1;
                    --$remaining[$catId];
                    break;
                }
            }
        }

        // Pass 2: fill each room up to its persons capacity, drawing first
        // from remaining adults, then from other occupancy categories.
        foreach ($reservations as $idx => $reservation) {
            $capacity = $reservation->getPersons();
            $taken = array_sum($perRoomCounts[$idx]);
            foreach ([...$adultIds, ...$otherOccupancyIds] as $catId) {
                while ($taken < $capacity && ($remaining[$catId] ?? 0) > 0) {
                    $perRoomCounts[$idx][$catId] = ($perRoomCounts[$idx][$catId] ?? 0) + 1;
                    --$remaining[$catId];
                    ++$taken;
                }
            }
        }

        // Pass 3: non-occupancy categories — all remaining attach to room 1.
        foreach ($nonOccupancyIds as $catId) {
            if (($remaining[$catId] ?? 0) > 0) {
                $perRoomCounts[0][$catId] = ($perRoomCounts[0][$catId] ?? 0) + $remaining[$catId];
                $remaining[$catId] = 0;
            }
        }

        foreach ($reservations as $idx => $reservation) {
            if ([] !== $perRoomCounts[$idx]) {
                $reservation->setGuestCounts($perRoomCounts[$idx]);
            }
        }
    }

    /**
     * Calculate the room-only total using session-free apartment position building.
     *
     * Room rates, per-guest adjustments and tourist tax come back as three separate
     * breakdowns. The adjustments in particular must not be folded into the room line:
     * the guest was shown a list price in step 2, and seeing that same number here with
     * the reduction spelled out underneath is the difference between a transparent offer
     * and a total that appears to have changed on its own.
     *
     * @param Reservation[] $reservations
     * @return array{roomTotal: float, roomTotalFormatted: string, roomPriceBreakdown: array<int, array{label: string, quantity: int, total: float, totalFormatted: string}>, modifierTotal: float, modifierBreakdown: array<int, array{label: string, total: float, totalFormatted: string}>, touristTaxTotal: float, touristTaxTotalFormatted: string, touristTaxLines: array<int, array{label: string, total: float, totalFormatted: string}>}
     */
    private function calculateRoomTotal(array $reservations): array
    {
        $apartmentTotal = 0.0;
        $breakdown = [];
        $modifierTotal = 0.0;
        $modifierLines = [];
        $touristTaxTotal = 0.0;
        $touristTaxLines = [];

        foreach ($reservations as $reservation) {
            $roomTotal = $this->pricingService->calculateReservationRoomTotal($reservation);

            $label = $this->buildReservationTypeLabel($reservation);
            if (!isset($breakdown[$label])) {
                $breakdown[$label] = [
                    'label' => $label,
                    'quantity' => 0,
                    'total' => 0.0,
                ];
            }

            $breakdown[$label]['quantity']++;
            $breakdown[$label]['total'] += $roomTotal->room;
            $apartmentTotal += $roomTotal->room;
            $modifierTotal += $roomTotal->modifiers;

            // Same wording the invoice uses, so the guest recognises the line later.
            // Identical adjustments across rooms collapse into one entry.
            foreach ($roomTotal->modifierPositions as $position) {
                $lineLabel = (string) $position->getDescription();
                $modifierLines[$lineLabel] ??= ['label' => $lineLabel, 'total' => 0.0];
                $modifierLines[$lineLabel]['total'] += $position->getAmount() * (float) $position->getPrice();
            }

            // Tourist-tax breakdown stays a separate line on the preview so
            // the guest sees the levy distinctly from the room rate.
            if (null !== $this->touristTaxService) {
                foreach ($this->touristTaxService->calculateForReservation($reservation) as $row) {
                    $rowTotal = $row->total();
                    $touristTaxTotal += $rowTotal;
                    $lineKey = $this->touristTaxPreviewLineKey($row);
                    if (!isset($touristTaxLines[$lineKey])) {
                        $touristTaxLines[$lineKey] = [
                            'label' => $row->taxName.(strlen($row->categoryName) > 0 ? ' — ' . $row->categoryName : ''),
                            'total' => 0.0,
                        ];
                    }
                    $touristTaxLines[$lineKey]['total'] += $rowTotal;
                }
            }
        }

        $formattedBreakdown = array_map(static function (array $row): array {
            $row['totalFormatted'] = PublicPricingService::formatAmount((float) $row['total']);

            return $row;
        }, array_values($breakdown));
        $formattedTouristTaxLines = array_map(static function (array $row): array {
            $row['totalFormatted'] = PublicPricingService::formatAmount((float) $row['total']);

            return $row;
        }, array_values($touristTaxLines));
        $formattedModifierLines = array_map(static function (array $row): array {
            $row['totalFormatted'] = PublicPricingService::formatAmount((float) $row['total']);

            return $row;
        }, array_values($modifierLines));

        return [
            'roomTotal' => $apartmentTotal,
            'roomTotalFormatted' => PublicPricingService::formatAmount($apartmentTotal),
            'roomPriceBreakdown' => $formattedBreakdown,
            'modifierTotal' => $modifierTotal,
            'modifierBreakdown' => $formattedModifierLines,
            'touristTaxTotal' => $touristTaxTotal,
            'touristTaxTotalFormatted' => PublicPricingService::formatAmount($touristTaxTotal),
            'touristTaxLines' => $formattedTouristTaxLines,
        ];
    }

    private function touristTaxPreviewLineKey(\App\Dto\TouristTaxBreakdown $row): string
    {
        return implode('|', [
            $row->calculationMode->value,
            $row->taxId,
            $row->taxName,
            $row->categoryId,
            $row->categoryName,
            number_format($row->pricePerNight, 6, '.', ''),
            null === $row->percentageRate ? '' : number_format($row->percentageRate, 6, '.', ''),
        ]);
    }

    /** Build a readable room type label for price breakdown rows. */
    private function buildReservationTypeLabel(Reservation $reservation): string
    {
        $room = $reservation->getAppartment();
        $category = $room->getRoomCategory();

        if (null !== $category && null !== $category->getName() && '' !== trim($category->getName())) {
            return trim($category->getName());
        }

        return trim(sprintf('%s - %s', (string) $room->getNumber(), (string) $room->getDescription()));
    }

    /**
     * Reuse a customer by email when possible or create a public-booking customer with contact details.
     *
     * @param array<string, string> $booker
     */
    private function findOrCreateBookerCustomer(array $booker): Customer
    {
        $salutation = self::sanitize($booker['salutation'] ?? '', 100);
        $firstname = self::sanitize($booker['firstname'] ?? '', 100);
        $lastname = self::sanitize($booker['lastname'] ?? '', 100);
        $email = self::sanitize($booker['email'] ?? '', 180);
        $normalizedEmail = mb_strtolower($email);
        if ('' === $salutation || '' === $firstname || '' === $lastname || '' === $normalizedEmail) {
            throw new PublicBookingException('online_booking.error.booker_required');
        }

        if (!filter_var($normalizedEmail, \FILTER_VALIDATE_EMAIL)) {
            throw new PublicBookingException('online_booking.error.invalid_email');
        }

        if (
            '' === self::sanitize($booker['address'] ?? '')
            || '' === self::sanitize($booker['zip'] ?? '')
            || '' === self::sanitize($booker['city'] ?? '')
            || '' === self::sanitize($booker['country'] ?? '')
        ) {
            throw new PublicBookingException('online_booking.error.booker_required');
        }

        /** @var CustomerRepository $customerRepository */
        $customerRepository = $this->em->getRepository(Customer::class);
        $customer = $customerRepository->findOneByEmailCaseInsensitive($normalizedEmail);

        if ($customer instanceof Customer) {
            $this->updateExistingCustomerContact($customer, $booker, $normalizedEmail);

            return $customer;
        }

        $customer = new Customer();
        $customer->setSalutation($salutation);
        $customer->setFirstname($firstname);
        $customer->setLastname($lastname);

        $address = new CustomerAddresses();
        $this->applyBookerDataToAddress($address, $booker, $normalizedEmail);
        $customer->addCustomerAddress($address);

        $this->em->persist($address);
        $this->em->persist($customer);
        $this->em->flush();

        return $customer;
    }

    /**
     * Update only missing customer contact fields so public bookings do not overwrite curated CRM data.
     *
     * @param array<string, string> $booker
     */
    private function updateExistingCustomerContact(Customer $customer, array $booker, string $email): void
    {
        $updated = false;
        $firstAddress = null;

        if ((null === $customer->getSalutation() || '' === trim((string) $customer->getSalutation())) && '' !== self::sanitize($booker['salutation'] ?? '', 100)) {
            $customer->setSalutation(self::sanitize($booker['salutation'] ?? '', 100));
            $updated = true;
        }
        if ((null === $customer->getFirstname() || '' === trim((string) $customer->getFirstname())) && '' !== self::sanitize($booker['firstname'] ?? '', 100)) {
            $customer->setFirstname(self::sanitize($booker['firstname'] ?? '', 100));
            $updated = true;
        }
        if ('' === trim((string) $customer->getLastname()) && '' !== self::sanitize($booker['lastname'] ?? '', 100)) {
            $customer->setLastname(self::sanitize($booker['lastname'] ?? '', 100));
            $updated = true;
        }
        foreach ($customer->getCustomerAddresses() as $address) {
            if (!$address instanceof CustomerAddresses) {
                continue;
            }
            $firstAddress ??= $address;

            if (null !== $address->getEmail() && mb_strtolower((string) $address->getEmail()) === $email) {
                $updated = $this->mergeMissingBookerDataIntoAddress($address, $booker, $email) || $updated;
                if ($updated) {
                    $this->em->persist($customer);
                    $this->em->persist($address);
                    $this->em->flush();
                }

                return;
            }
        }

        if ($firstAddress instanceof CustomerAddresses) {
            $updated = $this->mergeMissingBookerDataIntoAddress($firstAddress, $booker, $email) || $updated;
            if ($updated) {
                $this->em->persist($customer);
                $this->em->persist($firstAddress);
                $this->em->flush();
            }

            return;
        }

        $address = new CustomerAddresses();
        $this->applyBookerDataToAddress($address, $booker, $email);
        $customer->addCustomerAddress($address);

        $this->em->persist($address);
        $this->em->persist($customer);
        $this->em->flush();
    }

    /**
     * Apply the submitted public-booking address payload to a customer address entity.
     *
     * @param array<string, string> $booker
     */
    private function applyBookerDataToAddress(CustomerAddresses $address, array $booker, string $email): void
    {
        $company = self::sanitize($booker['company'] ?? '', 150);

        $address->setType('' !== $company ? 'CUSTOMER_ADDRESS_TYPE_BUSINESS' : 'CUSTOMER_ADDRESS_TYPE_PRIVATE');
        $address->setCompany('' !== $company ? $company : null);
        $address->setAddress(self::sanitize($booker['address'] ?? '', 200) ?: null);
        $address->setZip(self::sanitize($booker['zip'] ?? '', 20) ?: null);
        $address->setCity(self::sanitize($booker['city'] ?? '', 100) ?: null);
        $address->setCountry(self::sanitize($booker['country'] ?? '', 5) ?: null);
        $address->setEmail($email);
        $address->setPhone(self::sanitize($booker['phone'] ?? '', 50) ?: null);
    }

    /**
     * Merge only missing address fields from the public-booking payload into an existing address.
     *
     * @param array<string, string> $booker
     */
    private function mergeMissingBookerDataIntoAddress(CustomerAddresses $address, array $booker, string $email): bool
    {
        $updated = false;
        $company = self::sanitize($booker['company'] ?? '', 150);

        if ((null === $address->getEmail() || '' === trim((string) $address->getEmail())) && '' !== $email) {
            $address->setEmail($email);
            $updated = true;
        }
        if ((null === $address->getPhone() || '' === trim((string) $address->getPhone())) && '' !== self::sanitize($booker['phone'] ?? '', 50)) {
            $address->setPhone(self::sanitize($booker['phone'] ?? '', 50));
            $updated = true;
        }
        if ((null === $address->getAddress() || '' === trim((string) $address->getAddress())) && '' !== self::sanitize($booker['address'] ?? '', 200)) {
            $address->setAddress(self::sanitize($booker['address'] ?? '', 200));
            $updated = true;
        }
        if ((null === $address->getZip() || '' === trim((string) $address->getZip())) && '' !== self::sanitize($booker['zip'] ?? '', 20)) {
            $address->setZip(self::sanitize($booker['zip'] ?? '', 20));
            $updated = true;
        }
        if ((null === $address->getCity() || '' === trim((string) $address->getCity())) && '' !== self::sanitize($booker['city'] ?? '', 100)) {
            $address->setCity(self::sanitize($booker['city'] ?? '', 100));
            $updated = true;
        }
        if ((null === $address->getCountry() || '' === trim((string) $address->getCountry())) && '' !== self::sanitize($booker['country'] ?? '', 5)) {
            $address->setCountry(self::sanitize($booker['country'] ?? '', 5));
            $updated = true;
        }
        if ((null === $address->getCompany() || '' === trim((string) $address->getCompany())) && '' !== $company) {
            $address->setCompany($company);
            $address->setType('CUSTOMER_ADDRESS_TYPE_BUSINESS');
            $updated = true;
        }

        return $updated;
    }

    /**
     * Build the extras catalogue for the room-selection step, using one representative room per
     * available category so that category-bound extras of every offered category are surfaced.
     *
     * @param array<int, array{typeKey: string, roomIds: int[]}> $availability
     * @return array<int, array<string, mixed>>
     */
    private function loadBookableExtras(array $availability, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo, int $persons, int $roomsCount): array
    {
        $samples = [];
        foreach ($availability as $row) {
            if (empty($row['roomIds'])) {
                continue;
            }
            $sampleRoom = $this->appartmentRepository->find($row['roomIds'][0]);
            if (null === $sampleRoom) {
                continue;
            }
            $category = $sampleRoom->getRoomCategory();
            $samples[] = [
                'categoryId' => $category?->getId(),
                'categoryName' => $category?->getName(),
                'sampleRoom' => $sampleRoom,
            ];
        }

        if ([] === $samples) {
            return [];
        }

        return $this->pricingService->catalogExtras($samples, $dateFrom, $dateTo, $persons, $roomsCount);
    }

    /**
     * Group the booked rooms by room category for category-aware extra resolution.
     *
     * @param array<int, array{room: Appartment, persons: int}> $assignedRoomsWithPersons
     * @return array{buckets: array<int, array{categoryId: ?int, categoryName: ?string, sampleRoom: Appartment, roomCount: int, persons: int}>, totalRooms: int, totalPersons: int}
     */
    private function buildExtrasComposition(array $assignedRoomsWithPersons): array
    {
        $buckets = [];
        $totalRooms = 0;
        $totalPersons = 0;

        foreach ($assignedRoomsWithPersons as $entry) {
            $room = $entry['room'];
            $persons = (int) $entry['persons'];
            $category = $room->getRoomCategory();
            $key = null !== $category ? 'c'.$category->getId() : 'null';

            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'categoryId' => $category?->getId(),
                    'categoryName' => $category?->getName(),
                    'sampleRoom' => $room,
                    'roomCount' => 0,
                    'persons' => 0,
                ];
            }
            ++$buckets[$key]['roomCount'];
            $buckets[$key]['persons'] += $persons;
            ++$totalRooms;
            $totalPersons += $persons;
        }

        return ['buckets' => array_values($buckets), 'totalRooms' => $totalRooms, 'totalPersons' => $totalPersons];
    }

    /**
     * Aggregate resolved extras into a total + breakdown for display.
     *
     * @param array<int, array<string, mixed>> $resolved Output of PublicPricingService::resolveExtras()
     * @return array{extrasTotal: float, extrasTotalFormatted: string, extrasBreakdown: array<int, array{label: string, quantity: int, total: float, totalFormatted: string}>}
     */
    private function summarizeExtras(array $resolved): array
    {
        $extrasTotal = 0.0;
        $breakdown = [];

        foreach ($resolved as $extra) {
            $label = $extra['description'];
            if (null !== $extra['categoryName']) {
                $label .= ' ('.$extra['categoryName'].')';
            }
            $extrasTotal += $extra['lineTotal'];
            $breakdown[] = [
                'label' => $label,
                'quantity' => $extra['quantity'],
                'total' => $extra['lineTotal'],
                'totalFormatted' => $extra['lineTotalFormatted'],
            ];
        }

        return [
            'extrasTotal' => $extrasTotal,
            'extrasTotalFormatted' => PublicPricingService::formatAmount($extrasTotal),
            'extrasBreakdown' => $breakdown,
        ];
    }

    /**
     * Attach resolved extras to the right reservations, respecting room category and calculation type.
     *
     * @param Reservation[] $reservations
     * @param array<int, array<string, mixed>> $resolved
     */
    private function attachExtrasToReservations(array $reservations, array $resolved): void
    {
        foreach ($reservations as $resIndex => $reservation) {
            $resCategoryId = $reservation->getAppartment()?->getRoomCategory()?->getId();
            foreach ($resolved as $extra) {
                /** @var Price $price */
                $price = $extra['price'];
                $categoryId = $extra['categoryId'];

                if (null === $categoryId) {
                    // Global extra: per_person to all reservations, flat/per_room to the first $qty.
                    if ('per_person_night' === $extra['calculationType']) {
                        $reservation->addPrice($price);
                    } elseif ($resIndex < $extra['quantity']) {
                        $reservation->addPrice($price);
                    }
                    continue;
                }

                // Category-bound: attach to every reservation of the matching category. The misc-price
                // billing then yields the right multiplicity (flat → once per room, per_room → per
                // room-night, per_person → per person-night).
                if ($resCategoryId === $categoryId) {
                    $reservation->addPrice($price);
                }
            }
        }
    }

    /**
     * Strip control characters, trim, and enforce a maximum byte length for public input.
     */
    private static function sanitize(mixed $value, int $maxLength = 500): string
    {
        $str = trim((string) $value);
        // Remove ASCII control characters (0x00-0x1F, 0x7F) except common whitespace (tab, LF, CR)
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str) ?? $str;

        return mb_substr($str, 0, $maxLength);
    }
}
