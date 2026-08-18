<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use Doctrine\Common\Collections\ArrayCollection;
use App\Entity\OnlineBookingConfig;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Repository\AppartmentRepository;
use App\Repository\ReservationRepository;
use App\Service\AvailabilityService;
use App\Service\OnlineBookingConfigService;
use App\Service\OnlineBookingRestrictionService;
use App\Service\PublicAvailabilityService;
use App\Service\PublicPricingService;
use App\Service\RoomCategoryImageService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How much the public availability list gives away.
 *
 * The visible count per room type is capped at the number of rooms the guest asked
 * for, so an anonymous visitor cannot read the house's occupancy off the page. What
 * the cap deliberately does *not* do is decide whether a combination adds up — that
 * verdict belongs to the selection validation, which can explain itself to the guest.
 */
final class PublicAvailabilityInfoHidingTest extends TestCase
{
    private AppartmentRepository $appartmentRepository;
    private ReservationRepository $reservationRepository;
    private PublicPricingService $pricingService;
    private PublicAvailabilityService $service;

    protected function setUp(): void
    {
        $this->appartmentRepository = $this->createStub(AppartmentRepository::class);
        $this->reservationRepository = $this->createStub(ReservationRepository::class);
        $this->pricingService = $this->createStub(PublicPricingService::class);

        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getConfig')->willReturn($this->createStub(OnlineBookingConfig::class));
        $configService->method('getAllowedSubsidiaryIds')->willReturn([1]);
        $configService->method('getAllowedRoomIds')->willReturn([1, 2, 3, 4, 5, 6, 7, 8]);

        $restrictionService = $this->createStub(OnlineBookingRestrictionService::class);
        $restrictionService->method('isStayLongEnough')->willReturn(true);
        $restrictionService->method('getMinOccupancyForCategory')->willReturn(null);
        $restrictionService->method('getMaxRoomsForCategory')->willReturn(null);

        $availabilityService = $this->createStub(AvailabilityService::class);
        $availabilityService->method('getBlockedRoomIds')->willReturn([]);
        $availabilityService->method('isRoomAvailableFromPreloadedOccupancy')->willReturn(true);

        $this->reservationRepository->method('loadOccupancyByApartmentIdsWithoutStartEnd')->willReturn([]);
        $this->pricingService->method('getOccupancyPrices')->willReturnCallback(
            static function (?RoomCategory $category, Appartment $room, \DateTimeImmutable $from, \DateTimeImmutable $to, int $maxGuests): array {
                $options = [];
                for ($persons = 1; $persons <= $maxGuests; ++$persons) {
                    $options[$persons] = [
                        'persons' => $persons,
                        'totalPrice' => $persons * 50.0,
                        'totalPriceFormatted' => PublicPricingService::formatAmount($persons * 50.0),
                    ];
                }

                return $options;
            }
        );

        $this->service = new PublicAvailabilityService(
            $this->appartmentRepository,
            $this->reservationRepository,
            $configService,
            $restrictionService,
            $this->pricingService,
            $this->createStub(RoomCategoryImageService::class),
            $this->createStub(TranslatorInterface::class),
            $availabilityService,
        );
    }

    /** Eight free rooms, two requested: the page must not advertise more than two. */
    public function testVisibleCountIsCappedAtTheRequestedRoomCount(): void
    {
        $category = $this->roomCategory(1, 'Doppelzimmer');
        $this->appartmentRepository->method('findForPublicBooking')->willReturn([
            $this->createRoom(1, 2, $category),
            $this->createRoom(2, 2, $category),
            $this->createRoom(3, 2, $category),
            $this->createRoom(4, 2, $category),
            $this->createRoom(5, 2, $category),
            $this->createRoom(6, 2, $category),
            $this->createRoom(7, 2, $category),
            $this->createRoom(8, 2, $category),
        ]);

        $result = $this->service->getAvailability(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
            4,
            2,
        );

        self::assertCount(1, $result);
        self::assertSame(2, $result[0]['availableCount']);
        self::assertCount(2, $result[0]['roomIds'], 'roomIds must be trimmed alongside the visible count');
        self::assertSame([1, 2], $result[0]['roomIds']);
    }

    /** Fewer rooms than requested: the real count stays, nothing is invented. */
    public function testCountStaysBelowTheRequestedRoomCountWhenSupplyIsSmaller(): void
    {
        $category = $this->roomCategory(1, 'Doppelzimmer');
        $this->appartmentRepository->method('findForPublicBooking')->willReturn([
            $this->createRoom(1, 2, $category),
        ]);

        $result = $this->service->getAvailability(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
            3,
            3,
        );

        self::assertCount(1, $result);
        self::assertSame(1, $result[0]['availableCount']);
    }

    /**
     * Two singles cannot seat a party of four in two rooms, yet the type is still
     * listed. Hiding it would leave the guest guessing why the room vanished; the
     * selection validation rejects the pick with a message instead.
     */
    public function testTypeThatCannotSeatThePartyIsStillListed(): void
    {
        $singles = $this->roomCategory(1, 'Einzelzimmer');
        $this->appartmentRepository->method('findForPublicBooking')->willReturn([
            $this->createRoom(1, 1, $singles),
            $this->createRoom(2, 1, $singles),
        ]);

        $result = $this->service->getAvailability(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
            4,
            2,
        );

        self::assertCount(1, $result);
        self::assertSame('category:1', $result[0]['typeKey']);
        self::assertSame(2, $result[0]['availableCount']);
    }

    private function roomCategory(int $id, string $name): RoomCategory
    {
        $category = $this->createStub(RoomCategory::class);
        $category->method('getId')->willReturn($id);
        $category->method('getName')->willReturn($name);
        $category->method('getAmenities')->willReturn(new ArrayCollection());

        return $category;
    }

    private function createRoom(int $id, int $bedsMax, RoomCategory $category): Appartment
    {
        $subsidiary = $this->createStub(Subsidiary::class);
        $subsidiary->method('getId')->willReturn(1);

        $room = new Appartment();
        $room->setId($id);
        $room->setBedsMax($bedsMax);
        $room->setMultipleOccupancy(false);
        $room->setNumber((string) $id);
        $room->setDescription('Room '.$id);
        $room->setRoomCategory($category);
        $room->setObject($subsidiary);

        return $room;
    }
}
