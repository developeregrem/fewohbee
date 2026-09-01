<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PublicBooking\CalendarAvailability;
use App\Dto\PublicBooking\CalendarRoom;
use App\Entity\Appartment;
use App\Entity\OnlineBookingConfig;
use App\Repository\AppartmentRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Availability data for the guest-facing booking calendar.
 *
 * Everything a guest may see about a room's occupancy passes through here, so the
 * class owns the guard rails: only released rooms are addressable inside the
 * calendar window, the window never reaches past the configured booking horizon,
 * and the result carries per-night booleans plus pagination state and nothing else.
 */
class PublicBookingCalendarService
{
    /** Upper bound for installations that did not configure a booking horizon. */
    public const MAX_HORIZON_MONTHS = 24;

    /** Months a single request may load. */
    public const MAX_MONTHS_PER_REQUEST = 3;

    public function __construct(
        private readonly OnlineBookingConfigService $configService,
        private readonly AppartmentRepository $appartmentRepository,
        private readonly AvailabilityService $availabilityService,
        private readonly OnlineBookingRestrictionService $restrictionService,
    ) {
    }

    /**
     * Rooms offered in the calendar, in the order they should be presented.
     *
     * Rooms with multiple occupancy are excluded: their nights are shared between
     * several bookings, so a per-room free/taken calendar would misrepresent them.
     * They remain bookable through the search form.
     *
     * @return CalendarRoom[]
     */
    public function getSelectableRooms(?OnlineBookingConfig $config = null): array
    {
        $config ??= $this->configService->getConfig();
        if (!$config->isCalendarActive()) {
            return [];
        }

        $rooms = $this->loadEligibleRooms($config);

        return array_map(
            fn (Appartment $room): CalendarRoom => new CalendarRoom(
                (string) $room->getUuid(),
                $this->buildPublicLabel($room, $rooms),
                (int) $room->getBedsMax(),
            ),
            $rooms
        );
    }

    /**
     * How many released rooms the calendar could show, ignoring whether it is
     * switched on. The settings screen needs this to explain an empty calendar.
     */
    public function countEligibleRooms(?OnlineBookingConfig $config = null): int
    {
        return count($this->loadEligibleRooms($config ?? $this->configService->getConfig()));
    }

    /**
     * Per-night availability of one released room.
     *
     * @param string $roomUuid public room identifier
     * @param string $from     first month to load, as Y-m
     * @param int    $months   number of months, clamped to a small range
     *
     * @return CalendarAvailability|null null when the calendar is off, the input is
     *                                   unusable, or the room is not released inside
     *                                   the calendar window. At the horizon, every
     *                                   valid UUID receives the same empty end marker
     *                                   so the response does not confirm room scope
     */
    public function getAvailability(string $roomUuid, string $from, int $months, ?OnlineBookingConfig $config = null): ?CalendarAvailability
    {
        $config ??= $this->configService->getConfig();
        if (!$config->isEnabled() || !$config->isCalendarActive()) {
            return null;
        }

        $window = $this->resolveWindow($from, $months);
        if (null === $window) {
            return null;
        }

        [$start, $endExclusive, $hasMore] = $window;

        // Reaching the horizon is ordinary pagination, not a broken calendar. The
        // same empty result is returned for every syntactically valid UUID so this
        // edge case cannot be used to confirm whether a room exists.
        if ($start >= $endExclusive) {
            return Uuid::isValid($roomUuid)
                ? new CalendarAvailability(
                    $roomUuid,
                    $start->format('Y-m-d'),
                    $endExclusive->format('Y-m-d'),
                    [],
                    false,
                )
                : null;
        }

        $room = $this->findReleasedRoom($roomUuid, $config);
        if (!$room instanceof Appartment) {
            return null;
        }

        $occupied = $this->availabilityService->getOccupiedNightsForRoom($room, $start, $endExclusive);

        $nights = [];
        for ($night = $start; $night < $endExclusive; $night = $night->modify('+1 day')) {
            $key = $night->format('Y-m-d');
            $nights[$key] = !isset($occupied[$key]);
        }

        return new CalendarAvailability(
            (string) $room->getUuid(),
            $start->format('Y-m-d'),
            $endExclusive->format('Y-m-d'),
            $nights,
            $hasMore,
        );
    }

    /** Exclusive departure boundary derived from the configured booking horizon. */
    public function getHorizonEnd(): \DateTimeImmutable
    {
        $maxDeparture = $this->restrictionService->getMaxDepartureDate();
        $hardLimit = (new \DateTimeImmutable('today'))->modify(sprintf('+%d months', self::MAX_HORIZON_MONTHS));

        if (null === $maxDeparture) {
            return $hardLimit;
        }

        $configured = \DateTimeImmutable::createFromInterface($maxDeparture);

        return $configured < $hardLimit ? $configured : $hardLimit;
    }

    /**
     * Resolve a room the guest picked in the calendar, enforcing the release scope.
     *
     * The booking flow must never take a room identifier from the request at face
     * value, so this is the only way in.
     */
    public function findBookableRoom(string $roomUuid, ?OnlineBookingConfig $config = null): ?Appartment
    {
        $config ??= $this->configService->getConfig();
        if (!$config->isCalendarActive()) {
            return null;
        }

        return $this->findReleasedRoom($roomUuid, $config);
    }

    /** Resolve a released room by its public identifier. */
    private function findReleasedRoom(string $roomUuid, OnlineBookingConfig $config): ?Appartment
    {
        if (!Uuid::isValid($roomUuid)) {
            return null;
        }

        foreach ($this->loadRoomsInScope($config) as $room) {
            if (true === $room->isMultipleOccupancy()) {
                continue;
            }
            if (hash_equals((string) $room->getUuid(), $roomUuid)) {
                return $room;
            }
        }

        return null;
    }

    /**
     * Released rooms the calendar can represent.
     *
     * @return Appartment[]
     */
    private function loadEligibleRooms(OnlineBookingConfig $config): array
    {
        return array_values(array_filter(
            $this->loadRoomsInScope($config),
            static fn (Appartment $room): bool => true !== $room->isMultipleOccupancy()
        ));
    }

    /**
     * Rooms the hotelier released for online booking.
     *
     * @return Appartment[]
     */
    private function loadRoomsInScope(OnlineBookingConfig $config): array
    {
        return $this->appartmentRepository->findForPublicBooking(
            $this->configService->getAllowedRoomIds($config),
            $this->configService->getAllowedSubsidiaryIds($config),
        );
    }

    /**
     * Clamp the requested window to [today's month, booking horizon].
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: bool}|null
     */
    private function resolveWindow(string $from, int $months): ?array
    {
        if (1 !== preg_match('/^\d{4}-\d{2}$/', $from)) {
            return null;
        }

        $requested = \DateTimeImmutable::createFromFormat('!Y-m-d', $from.'-01');
        if (false === $requested) {
            return null;
        }

        $currentMonth = (new \DateTimeImmutable('today'))->modify('first day of this month');
        $start = $requested < $currentMonth ? $currentMonth : $requested;

        $months = max(1, min(self::MAX_MONTHS_PER_REQUEST, $months));
        $endExclusive = $start->modify(sprintf('+%d months', $months));

        $horizonEnd = $this->getHorizonEnd();
        if ($start >= $horizonEnd) {
            return [$horizonEnd, $horizonEnd, false];
        }

        if ($endExclusive > $horizonEnd) {
            $endExclusive = $horizonEnd;
        }

        return [$start, $endExclusive, $endExclusive < $horizonEnd];
    }

    /**
     * Guest-facing room name: the category, disambiguated by room number only when
     * several released rooms share that category.
     *
     * @param Appartment[] $allRooms
     */
    private function buildPublicLabel(Appartment $room, array $allRooms): string
    {
        $categoryName = $room->getRoomCategory()?->getName();
        if (null === $categoryName || '' === trim($categoryName)) {
            return (string) $room->getNumber();
        }

        $sameCategory = 0;
        foreach ($allRooms as $candidate) {
            if ($candidate->getRoomCategory()?->getName() === $categoryName) {
                ++$sameCategory;
            }
        }

        return $sameCategory > 1
            ? sprintf('%s – %s', $categoryName, $room->getNumber())
            : $categoryName;
    }
}
