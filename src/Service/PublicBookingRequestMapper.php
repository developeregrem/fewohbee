<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PublicBooking\BookerInput;
use App\Dto\PublicBooking\PublicBookingRequest;
use App\Entity\Appartment;
use App\Exception\InvalidReservationPeriodException;
use App\Exception\PublicBookingException;
use App\Repository\GuestCategoryRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Turns one public booking POST into a validated {@see PublicBookingRequest}.
 *
 * Deliberately hand-rolled instead of a Symfony form type: the occupancy fields are
 * named dynamically (`occ_{typeKey}_p{persons}`) from whatever availability the guest
 * was shown, and the extras likewise (`extra_{priceId}`). A form type would need the
 * full field list up front, which is only known after the availability query has run.
 * Everything here therefore reads the request bag directly and throws
 * {@see PublicBookingException} with a translation key on bad input.
 */
class PublicBookingRequestMapper
{
    public function __construct(
        private readonly GuestCategoryRepository $guestCategoryRepository,
        private readonly GuestCategoryAgeMapper $ageMapper,
        private readonly ReservationPeriodService $reservationPeriodService,
    ) {
    }

    /**
     * @param Appartment|null $calendarRoom the room the guest picked in the calendar, already scope-checked
     * @param string          $defaultCountry ISO country used when the guest left the field empty
     *
     * @throws PublicBookingException on unusable dates
     */
    public function map(Request $request, ?Appartment $calendarRoom, string $defaultCountry): PublicBookingRequest
    {
        $intent = self::readIntent($request);
        [$dateFrom, $dateTo, $persons, $roomsCount] = $this->parseSearchInput($request);

        $guestCounts = $this->resolveGuestCounts($request);
        $persons = $this->deriveOccupancy($guestCounts, $persons);

        $occupancySelection = $this->extractOccupancySelection($request);
        // Calendar mode has no occupancy control of its own: the guest already picked
        // the room, so the selection follows from that room and the stated occupancy.
        if (null !== $calendarRoom && 'availability' !== $intent) {
            $occupancySelection = self::buildCalendarSelection($calendarRoom, $persons);
        }

        return new PublicBookingRequest(
            $intent,
            $dateFrom,
            $dateTo,
            $persons,
            $roomsCount,
            $guestCounts,
            $occupancySelection,
            $this->extractExtrasSelection($request),
            $this->mapBooker($request, $defaultCountry),
        );
    }

    /** Which wizard step the POST belongs to. Read separately because the error path needs it too. */
    public static function readIntent(Request $request): string
    {
        return (string) $request->request->get('intent', 'availability');
    }

    /**
     * Parse and validate the basic search inputs used in all public booking steps.
     *
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable,2:int,3:int}
     *
     * @throws PublicBookingException
     */
    private function parseSearchInput(Request $request): array
    {
        $dateFromRaw = (string) $request->request->get('dateFrom', '');
        $dateToRaw = (string) $request->request->get('dateTo', '');
        if ('' === $dateFromRaw || '' === $dateToRaw) {
            throw new PublicBookingException('online_booking.error.dates_required');
        }

        try {
            $period = $this->reservationPeriodService->parse($dateFromRaw, $dateToRaw);
        } catch (InvalidReservationPeriodException $e) {
            // Public booking renders expected validation exceptions inside the wizard.
            $message = match ($e->getMessage()) {
                'reservation.period.invalid_dates' => 'online_booking.error.invalid_dates',
                'reservation.period.end_before_start' => 'online_booking.error.departure_after_arrival',
                default => 'online_booking.error.booking_horizon_exceeded',
            };

            throw new PublicBookingException($message);
        }
        $dateFrom = $period->start;
        $dateTo = $period->end;

        $persons = max(1, (int) $request->request->get('persons', 1));
        $roomsCount = max(1, (int) $request->request->get('roomsCount', 1));
        $minArrivalDate = new \DateTimeImmutable('today');

        if ($dateFrom < $minArrivalDate) {
            throw new PublicBookingException('online_booking.error.arrival_must_be_future');
        }

        return [$dateFrom, $dateTo, $persons, $roomsCount];
    }

    /**
     * Resolve the wizard's `adults` + `childAges[]` input into a `{categoryId: count}` map.
     *
     * The age mapper looks up each child's age against the configured GuestCategory
     * ranges, which keeps the public UI to two inputs even when the hotelier has
     * many child tiers.
     *
     * @return array<int, int>
     */
    private function resolveGuestCounts(Request $request): array
    {
        $adultsRaw = $request->request->get('adults');
        $childAgesRaw = $request->request->all('childAges');
        if (null === $adultsRaw && [] === $childAgesRaw) {
            return [];
        }

        $adults = max(0, (int) $adultsRaw);
        $childAges = [];
        foreach ($childAgesRaw as $age) {
            $age = (int) $age;
            if ($age >= 0 && $age <= 120) {
                $childAges[] = $age;
            }
        }

        return $this->ageMapper->map($adults, $childAges);
    }

    /**
     * The effective occupancy is what the guest is actually booked for, so only guests
     * that count towards it are summed — an infant in a cot does not take a bed. The
     * `persons` field stays the fallback for submissions without guest categories.
     *
     * @param array<int, int> $guestCounts
     */
    private function deriveOccupancy(array $guestCounts, int $fallback): int
    {
        if ([] === $guestCounts) {
            return $fallback;
        }

        $derived = 0;
        foreach ($this->guestCategoryRepository->findActiveOrdered() as $category) {
            if (!$category->isCountedInOccupancy()) {
                continue;
            }
            $derived += (int) ($guestCounts[(int) $category->getId()] ?? 0);
        }

        return $derived > 0 ? $derived : $fallback;
    }

    /**
     * Extract the occupancy-based selection from POST fields.
     *
     * Field format: occ_{typeKey}_p{persons} = quantity
     * Example: occ_category:1_p2 = 1 means "1 room of category:1 with 2 persons"
     *
     * @return array<string, array<int, int>> e.g. ['category:1' => [2 => 1]]
     */
    private function extractOccupancySelection(Request $request): array
    {
        $selection = [];
        foreach ($request->request->all() as $key => $value) {
            if (!str_starts_with($key, 'occ_')) {
                continue;
            }

            $remainder = substr($key, 4);
            $lastUnderscore = strrpos($remainder, '_p');
            if (false === $lastUnderscore) {
                continue;
            }

            $typeKey = substr($remainder, 0, $lastUnderscore);
            $personsStr = substr($remainder, $lastUnderscore + 2);
            if ('' === $typeKey || '' === $personsStr) {
                continue;
            }

            $persons = max(0, (int) $personsStr);
            $qty = max(0, (int) $value);
            if ($persons > 0) {
                $selection[$typeKey][$persons] = $qty;
            }
        }

        return $selection;
    }

    /**
     * Extract selected extras with quantities from POST fields.
     *
     * Field format: extra_{priceId} = quantity (1+ means selected)
     *
     * @return array<int, int> Map of Price ID => quantity
     */
    private function extractExtrasSelection(Request $request): array
    {
        $selected = [];
        foreach ($request->request->all() as $key => $value) {
            if (!str_starts_with($key, 'extra_')) {
                continue;
            }
            $priceId = (int) substr($key, 6);
            $qty = max(0, (int) $value);
            if ($priceId > 0 && $qty > 0) {
                $selected[$priceId] = $qty;
            }
        }

        return $selected;
    }

    /**
     * Extract the public booker/contact payload.
     *
     * Public because the view model re-reads it to redisplay what the guest typed —
     * both sides must agree on the field names, so there is only one extraction.
     */
    public function mapBooker(Request $request, string $defaultCountry): BookerInput
    {
        return new BookerInput(
            (string) $request->request->get('salutation', ''),
            (string) $request->request->get('firstname', ''),
            (string) $request->request->get('lastname', ''),
            (string) $request->request->get('email', ''),
            (string) $request->request->get('phone', ''),
            (string) $request->request->get('company', ''),
            (string) $request->request->get('address', ''),
            (string) $request->request->get('zip', ''),
            (string) $request->request->get('city', ''),
            mb_strtoupper((string) $request->request->get('country', $defaultCountry)),
            (string) $request->request->get('comment', ''),
        );
    }

    /**
     * The single room from the calendar, expressed in the same occupancy currency
     * the search path uses, so everything downstream stays one code path.
     *
     * @return array<string, array<int, int>>
     */
    private static function buildCalendarSelection(Appartment $room, int $persons): array
    {
        if ($persons < 1) {
            return [];
        }

        $category = $room->getRoomCategory();
        $typeKey = null !== $category ? 'category:'.$category->getId() : 'apartment:'.$room->getId();

        return [$typeKey => [$persons => 1]];
    }
}
