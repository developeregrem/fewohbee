<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\Api;

use App\Dto\Api\PriceDto;
use App\Entity\Appartment;
use App\Entity\GuestCategory;
use App\Entity\ReservationOrigin;
use App\Entity\RoomCategory;
use App\Repository\GuestCategoryRepository;
use App\Repository\PriceRepository;
use App\Security\Voter\ApiScopeVoter;
use App\Service\Api\PriceQuoteService;
use App\Service\Api\RateCalendarService;
use App\Service\OnlineBookingConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Token-authenticated pricing endpoints.
 *
 * Three distinct things, deliberately kept apart:
 *  - /prices        the configured price rows (reference data)
 *  - /prices/rates  which row applies on which night, per occupancy
 *  - /prices/quote  what a concrete stay costs, computed by the invoice pipeline
 *
 * Only the quote answers "how much"; deriving that from the catalogue means
 * reimplementing the calculation and drifting away from the invoice.
 */
#[Route('/api/v1/prices')]
#[IsGranted(ApiScopeVoter::PRICES_READ)]
class PriceApiController extends AbstractController
{
    private const MAX_QUOTE_NIGHTS = 366;
    /** Matches the full-sync window channel managers ask for (500 days). */
    private const MAX_RATE_DAYS = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PriceRepository $priceRepository,
        private readonly GuestCategoryRepository $guestCategoryRepository,
        private readonly OnlineBookingConfigService $bookingConfigService,
        private readonly PriceQuoteService $priceQuoteService,
        private readonly RateCalendarService $rateCalendarService,
    ) {
    }

    /**
     * The price catalogue — configured price rows with their periods, origins and components.
     */
    #[Route('', name: 'api.prices.list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $type = $this->resolveType($request);
        $roomCategory = $this->resolveRoomCategory($request);
        $originIds = $this->resolveOriginIds($request);
        $active = $this->resolveActive($request);
        $bookableOnline = $this->parseOptionalBool($request, 'bookableOnline');

        $prices = $this->priceRepository->findForCatalogue(
            $type,
            $roomCategory?->getId(),
            $originIds,
            $active,
            $bookableOnline,
        );

        $data = array_map(static fn ($price): PriceDto => PriceDto::fromEntity($price), $prices);

        return $this->envelope($data, [
            'type' => null === $type ? null : (2 === $type ? 'apartment' : 'misc'),
            'roomCategoryId' => $roomCategory?->getId(),
            'originIds' => [] === $originIds ? null : $originIds,
            'active' => $active,
            'bookableOnline' => $bookableOnline,
        ]);
    }

    /**
     * What a stay would cost. Computed through the same pipeline as the invoice.
     */
    #[Route('/quote', name: 'api.prices.quote', methods: ['GET'])]
    public function quote(Request $request): JsonResponse
    {
        $apartment = $this->resolveApartment($request, true);
        [$start, $end] = $this->parseStayRange($request);
        $origin = $this->resolveOrigin($request);
        $guestCounts = $this->resolveGuestCounts($request);
        $persons = $this->resolvePersons($request, $guestCounts);

        $quote = $this->priceQuoteService->quote(
            $apartment,
            $start,
            $end,
            $persons,
            $guestCounts,
            $origin,
            $this->hasTouristTaxScope(),
        );

        return new JsonResponse([
            'data' => $quote,
            'meta' => [
                'touristTaxIncluded' => null !== $quote->touristTax,
            ],
        ]);
    }

    /**
     * Per-night, per-occupancy rate calendar for one room category.
     */
    #[Route('/rates', name: 'api.prices.rates', methods: ['GET'])]
    public function rates(Request $request): JsonResponse
    {
        [$firstNight, $lastNight] = $this->parseNightRange($request);
        $origin = $this->resolveOrigin($request);
        $nights = $this->parsePositiveInt($request, 'nights') ?? 1;

        $apartment = $this->resolveApartment($request, false);
        $roomCategory = $apartment?->getRoomCategory() ?? $this->resolveRoomCategory($request);
        if (null === $roomCategory) {
            throw new BadRequestHttpException("Parameter 'roomCategoryId' or 'apartmentId' is required.");
        }

        $sampleRoom = $apartment ?? $this->em->getRepository(Appartment::class)
            ->findOneBy(['roomCategory' => $roomCategory]);
        if (!$sampleRoom instanceof Appartment) {
            throw new BadRequestHttpException('No apartment is assigned to this room category.');
        }

        $occupancies = $this->resolveOccupancies($request, $roomCategory);

        $data = $this->rateCalendarService->build(
            $sampleRoom,
            $firstNight,
            $lastNight,
            $nights,
            $occupancies,
            $origin,
        );

        return $this->envelope($data, [
            'start' => $firstNight->format('Y-m-d'),
            'end' => $lastNight->format('Y-m-d'),
            'nights' => $nights,
            'roomCategoryId' => $roomCategory->getId(),
            'occupancies' => $occupancies,
            'origin' => ['id' => (int) $origin->getId(), 'name' => $origin->getName()],
        ]);
    }

    /**
     * Tourist tax amounts in a quote are gated on the tourist-tax scope, the same way
     * invoice references in a reservation are gated on the invoices scope: the field
     * comes back null rather than absent, so "not permitted" stays distinguishable
     * from "nothing applies".
     */
    private function hasTouristTaxScope(): bool
    {
        return $this->isGranted(ApiScopeVoter::TOURIST_TAX_READ);
    }

    private function resolveApartment(Request $request, bool $required): ?Appartment
    {
        $id = $request->query->get('apartmentId');
        if (null === $id || '' === $id) {
            if ($required) {
                throw new BadRequestHttpException("Parameter 'apartmentId' is required.");
            }

            return null;
        }
        $apartment = $this->em->getRepository(Appartment::class)->find((int) $id);
        if (!$apartment instanceof Appartment) {
            throw new BadRequestHttpException("Unknown 'apartmentId'.");
        }

        return $apartment;
    }

    private function resolveRoomCategory(Request $request): ?RoomCategory
    {
        $id = $request->query->get('roomCategoryId');
        if (null === $id || '' === $id) {
            return null;
        }
        $category = $this->em->getRepository(RoomCategory::class)->find((int) $id);
        if (!$category instanceof RoomCategory) {
            throw new BadRequestHttpException("Unknown 'roomCategoryId'.");
        }

        return $category;
    }

    /**
     * Prices are joined to a reservation origin, so a quote without one cannot be
     * calculated at all — different origins legitimately carry different price rows.
     * Falls back to the origin configured for online booking.
     */
    private function resolveOrigin(Request $request): ReservationOrigin
    {
        $id = $request->query->get('originId');
        if (null !== $id && '' !== $id) {
            $origin = $this->em->getRepository(ReservationOrigin::class)->find((int) $id);
            if (!$origin instanceof ReservationOrigin) {
                throw new BadRequestHttpException("Unknown 'originId'.");
            }

            return $origin;
        }

        $origin = $this->bookingConfigService->getReservationOrigin();
        if (!$origin instanceof ReservationOrigin) {
            throw new BadRequestHttpException(
                "Parameter 'originId' is required: no default booking origin is configured."
            );
        }

        return $origin;
    }

    /**
     * @return array<int, int> guest category id => head count
     */
    private function resolveGuestCounts(Request $request): array
    {
        $param = $request->query->all()['guestCounts'] ?? null;
        if (null === $param || '' === $param || [] === $param) {
            return [];
        }
        if (!\is_array($param)) {
            throw new BadRequestHttpException("Parameter 'guestCounts' must be given as guestCounts[categoryId]=count.");
        }

        $categories = [];
        foreach ($this->guestCategoryRepository->findActiveOrdered() as $category) {
            $categories[(int) $category->getId()] = $category;
        }

        $result = [];
        foreach ($param as $categoryId => $count) {
            $categoryId = (int) $categoryId;
            if (!isset($categories[$categoryId])) {
                throw new BadRequestHttpException(sprintf("Unknown guest category '%d' in 'guestCounts'.", $categoryId));
            }
            if (!is_numeric($count) || (int) $count < 0) {
                throw new BadRequestHttpException("Values in 'guestCounts' must be non-negative integers.");
            }
            if ((int) $count > 0) {
                $result[$categoryId] = (int) $count;
            }
        }

        return $result;
    }

    /**
     * @param array<int, int> $guestCounts
     */
    private function resolvePersons(Request $request, array $guestCounts): int
    {
        $persons = $this->parsePositiveInt($request, 'persons');
        if (null !== $persons) {
            return $persons;
        }

        // Occupancy is what the apartment price is matched against, and only categories
        // flagged as counting toward occupancy belong in it (an infant in a cot does not).
        $categories = [];
        foreach ($this->guestCategoryRepository->findActiveOrdered() as $category) {
            $categories[(int) $category->getId()] = $category;
        }
        $sum = 0;
        foreach ($guestCounts as $categoryId => $count) {
            $category = $categories[$categoryId] ?? null;
            if ($category instanceof GuestCategory && $category->isCountedInOccupancy()) {
                $sum += $count;
            }
        }

        if ($sum < 1) {
            throw new BadRequestHttpException("Parameter 'persons' is required when 'guestCounts' carries no occupancy-counted guests.");
        }

        return $sum;
    }

    /**
     * @return int[]
     */
    private function resolveOccupancies(Request $request, RoomCategory $roomCategory): array
    {
        $occupancy = $this->parsePositiveInt($request, 'occupancy');
        if (null !== $occupancy) {
            return [$occupancy];
        }

        // Without an explicit occupancy, report every one the category actually has a
        // price for — numberOfPersons is matched exactly, so any other value is empty.
        $occupancies = $this->priceRepository->findOccupanciesForRoomCategory($roomCategory);

        return [] === $occupancies ? [] : $occupancies;
    }

    private function resolveType(Request $request): ?int
    {
        $type = $request->query->get('type');
        if (null === $type || '' === $type) {
            return null;
        }

        return match ($type) {
            'apartment' => 2,
            'misc' => 1,
            default => throw new BadRequestHttpException("Parameter 'type' must be 'apartment' or 'misc'."),
        };
    }

    /**
     * @return int[]
     */
    private function resolveOriginIds(Request $request): array
    {
        $param = $request->query->all()['originId'] ?? null;
        if (null === $param || '' === $param || [] === $param) {
            return [];
        }
        $values = \is_array($param) ? $param : explode(',', (string) $param);

        $result = [];
        foreach ($values as $value) {
            $origin = $this->em->getRepository(ReservationOrigin::class)->find((int) $value);
            if (!$origin instanceof ReservationOrigin) {
                throw new BadRequestHttpException("Unknown 'originId'.");
            }
            $result[] = (int) $origin->getId();
        }

        return array_values(array_unique($result));
    }

    /**
     * @return bool|null null means "no filter" (active=all)
     */
    private function resolveActive(Request $request): ?bool
    {
        $value = $request->query->get('active');
        if (null === $value || '' === $value) {
            return true;
        }
        if ('all' === $value) {
            return null;
        }

        return $this->parseOptionalBool($request, 'active');
    }

    private function parseOptionalBool(Request $request, string $paramName): ?bool
    {
        $value = $request->query->get($paramName);
        if (null === $value || '' === $value) {
            return null;
        }
        if (\in_array($value, ['1', 'true'], true)) {
            return true;
        }
        if (\in_array($value, ['0', 'false'], true)) {
            return false;
        }

        throw new BadRequestHttpException(sprintf("Parameter '%s' must be true or false.", $paramName));
    }

    private function parsePositiveInt(Request $request, string $paramName): ?int
    {
        $value = $request->query->get($paramName);
        if (null === $value || '' === $value) {
            return null;
        }
        if (!preg_match('/^\d+$/', (string) $value) || (int) $value < 1) {
            throw new BadRequestHttpException(sprintf("Parameter '%s' must be a positive integer.", $paramName));
        }

        return (int) $value;
    }

    /**
     * Arrival and departure date. `end` is the departure day and therefore exclusive —
     * a stay from the 1st to the 8th is seven nights, exactly as in the reservation.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function parseStayRange(Request $request): array
    {
        $start = $this->parseDate($request->query->get('start'), 'start');
        $end = $this->parseDate($request->query->get('end'), 'end');
        if (null === $start || null === $end) {
            throw new BadRequestHttpException("Parameters 'start' and 'end' are required (format Y-m-d).");
        }
        if ($end <= $start) {
            throw new BadRequestHttpException("Parameter 'end' must be after 'start' (it is the departure date).");
        }
        if ((int) $start->diff($end)->days > self::MAX_QUOTE_NIGHTS) {
            throw new BadRequestHttpException(sprintf('Stay must not exceed %d nights.', self::MAX_QUOTE_NIGHTS));
        }

        return [$start, $end];
    }

    /**
     * First and last night, both inclusive — a rate calendar reports nights, not a stay.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function parseNightRange(Request $request): array
    {
        $start = $this->parseDate($request->query->get('start'), 'start') ?? new \DateTimeImmutable('today');
        $end = $this->parseDate($request->query->get('end'), 'end') ?? $start;
        if ($end < $start) {
            throw new BadRequestHttpException("Parameter 'end' must not be before 'start'.");
        }
        if ((int) $start->diff($end)->days + 1 > self::MAX_RATE_DAYS) {
            throw new BadRequestHttpException(sprintf('Date range must not exceed %d days.', self::MAX_RATE_DAYS));
        }

        return [$start, $end];
    }

    private function parseDate(?string $value, string $paramName): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException(sprintf("Invalid parameter '%s': expected format Y-m-d.", $paramName));
        }

        return $parsed->setTime(0, 0);
    }

    /**
     * @param list<mixed>          $data
     * @param array<string, mixed> $meta
     */
    private function envelope(array $data, array $meta): JsonResponse
    {
        return new JsonResponse([
            'data' => $data,
            'meta' => $meta + ['count' => \count($data)],
        ]);
    }
}
