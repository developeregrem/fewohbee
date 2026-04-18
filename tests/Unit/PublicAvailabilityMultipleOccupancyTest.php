<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\OnlineBookingConfig;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Repository\AppartmentRepository;
use App\Repository\ReservationRepository;
use App\Service\OnlineBookingConfigService;
use App\Service\OnlineBookingRestrictionService;
use App\Service\PublicAvailabilityService;
use App\Service\PublicPricingService;
use App\Service\RoomCategoryImageService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tests for multipleOccupancy handling in PublicAvailabilityService.
 */
final class PublicAvailabilityMultipleOccupancyTest extends TestCase
{
    private AppartmentRepository $appartmentRepository;
    private ReservationRepository $reservationRepository;
    private OnlineBookingConfigService $configService;
    private OnlineBookingRestrictionService $restrictionService;
    private PublicPricingService $pricingService;
    private RoomCategoryImageService $imageService;
    private PublicAvailabilityService $service;

    protected function setUp(): void
    {
        $this->appartmentRepository = $this->createStub(AppartmentRepository::class);
        $this->reservationRepository = $this->createStub(ReservationRepository::class);
        $this->configService = $this->createStub(OnlineBookingConfigService::class);
        $this->restrictionService = $this->createStub(OnlineBookingRestrictionService::class);
        $this->pricingService = $this->createStub(PublicPricingService::class);
        $this->imageService = $this->createStub(RoomCategoryImageService::class);

        $config = $this->createStub(OnlineBookingConfig::class);
        $this->configService->method('getConfig')->willReturn($config);
        $this->configService->method('getAllowedSubsidiaryIds')->willReturn([1]);
        $this->configService->method('getAllowedRoomIds')->willReturn([1]);

        $this->restrictionService->method('isStayLongEnough')->willReturn(true);
        $this->restrictionService->method('getMinOccupancyForCategory')->willReturn(null);

        $this->service = new PublicAvailabilityService(
            $this->appartmentRepository,
            $this->reservationRepository,
            $this->configService,
            $this->restrictionService,
            $this->pricingService,
            $this->imageService,
            $this->createStub(TranslatorInterface::class),
        );
    }

    private function createRoom(int $id, int $bedsMax, bool $multipleOccupancy, RoomCategory $category): Appartment
    {
        $subsidiary = $this->createStub(Subsidiary::class);
        $subsidiary->method('getId')->willReturn(1);

        $room = new Appartment();
        $room->setId($id);
        $room->setBedsMax($bedsMax);
        $room->setMultipleOccupancy($multipleOccupancy);
        $room->setNumber((string) $id);
        $room->setDescription('Room '.$id);
        $room->setRoomCategory($category);
        $room->setObject($subsidiary);

        return $room;
    }

    /**
     * A multipleOccupancy room fully occupied (persons == bedsMax) must not appear as available.
     */
    public function testFullyOccupiedMultipleOccupancyRoomIsNotAvailable(): void
    {
        $category = new RoomCategory();
        $room = $this->createRoom(1, 3, true, $category);

        $this->appartmentRepository->method('findForPublicBooking')->willReturn([$room]);
        $this->reservationRepository->method('loadOccupancyByApartmentIdsWithoutStartEnd')->willReturn([
            1 => ['reservationCount' => 3, 'persons' => 3],
        ]);

        $result = $this->service->getAvailability(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
            1,
            1,
        );

        self::assertSame([], $result, 'Fully occupied multipleOccupancy room should not be available');
    }

    /**
     * A multipleOccupancy room with remaining capacity should appear, but only offer
     * occupancy options up to the remaining capacity (not the full bedsMax).
     */
    public function testPartiallyOccupiedMultipleOccupancyRoomShowsReducedCapacity(): void
    {
        $category = new RoomCategory();
        $room = $this->createRoom(1, 3, true, $category);

        $this->appartmentRepository->method('findForPublicBooking')->willReturn([$room]);
        // 1 person already occupies the 3-bed room → 2 beds remaining
        $this->reservationRepository->method('loadOccupancyByApartmentIdsWithoutStartEnd')->willReturn([
            1 => ['reservationCount' => 1, 'persons' => 1],
        ]);

        $this->pricingService->method('getOccupancyPrices')->willReturnCallback(
            function (RoomCategory $cat, Appartment $sampleRoom, \DateTimeImmutable $from, \DateTimeImmutable $to, int $maxGuests): array {
                $options = [];
                for ($p = 1; $p <= $maxGuests; ++$p) {
                    $options[$p] = ['persons' => $p, 'totalPrice' => $p * 50.0, 'totalPriceFormatted' => number_format($p * 50.0, 2, ',', '.')];
                }

                return $options;
            }
        );

        $result = $this->service->getAvailability(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
            2,
            1,
        );

        self::assertCount(1, $result);
        // maxGuests should reflect remaining capacity (2), not full bedsMax (3)
        self::assertSame(2, $result[0]['maxGuests']);
        // occupancyOptions should only offer 1 or 2 persons, not 3
        $offeredPersons = array_column($result[0]['occupancyOptions'], 'persons');
        self::assertContains(1, $offeredPersons);
        self::assertContains(2, $offeredPersons);
        self::assertNotContains(3, $offeredPersons);
    }

    /**
     * A normal (non-multipleOccupancy) room with a reservation must not be available.
     */
    public function testNonMultipleOccupancyRoomWithReservationIsNotAvailable(): void
    {
        $category = new RoomCategory();
        $room = $this->createRoom(1, 3, false, $category);

        $this->appartmentRepository->method('findForPublicBooking')->willReturn([$room]);
        $this->reservationRepository->method('loadOccupancyByApartmentIdsWithoutStartEnd')->willReturn([
            1 => ['reservationCount' => 1, 'persons' => 1],
        ]);

        $result = $this->service->getAvailability(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
            1,
            1,
        );

        self::assertSame([], $result, 'Non-multipleOccupancy room with existing reservation should not be available');
    }

    /**
     * A multipleOccupancy room with no existing reservations should show full capacity.
     */
    public function testEmptyMultipleOccupancyRoomShowsFullCapacity(): void
    {
        $category = new RoomCategory();
        $room = $this->createRoom(1, 3, true, $category);

        $this->appartmentRepository->method('findForPublicBooking')->willReturn([$room]);
        $this->reservationRepository->method('loadOccupancyByApartmentIdsWithoutStartEnd')->willReturn([]);

        $this->pricingService->method('getOccupancyPrices')->willReturnCallback(
            function (RoomCategory $cat, Appartment $sampleRoom, \DateTimeImmutable $from, \DateTimeImmutable $to, int $maxGuests): array {
                $options = [];
                for ($p = 1; $p <= $maxGuests; ++$p) {
                    $options[$p] = ['persons' => $p, 'totalPrice' => $p * 50.0, 'totalPriceFormatted' => number_format($p * 50.0, 2, ',', '.')];
                }

                return $options;
            }
        );

        $result = $this->service->getAvailability(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
            2,
            1,
        );

        self::assertCount(1, $result);
        self::assertSame(3, $result[0]['maxGuests']);
    }
}
