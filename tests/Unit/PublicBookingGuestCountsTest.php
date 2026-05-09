<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Enum\GuestStatisticalGroup;
use App\Entity\GuestCategory;
use App\Entity\InvoiceAppartment;
use App\Entity\Reservation;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Repository\AppartmentRepository;
use App\Repository\GuestCategoryRepository;
use App\Service\InvoiceService;
use App\Service\MailService;
use App\Service\OnlineBookingConfigService;
use App\Service\PublicAvailabilityService;
use App\Service\PublicBookingService;
use App\Service\PublicPricingService;
use App\Service\TemplatesService;
use App\Service\TouristTaxService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PublicBookingGuestCountsTest extends TestCase
{
    public function testGuestCountsForSingleRoomBookingAppliedFully(): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT, true);
        $child = $this->makeCategory(2, GuestStatisticalGroup::CHILD, true);

        $reservations = [];
        $service = $this->makeService([$adult, $child], reservationCapture: $reservations);

        $availability = [[
            'typeKey' => 'category:1',
            'typeLabel' => 'Double',
            'typeDescription' => null,
            'maxGuests' => 3,
            'availableCount' => 1,
            'roomIds' => [101],
            'subsidiaryIds' => [1],
            'occupancyOptions' => [3 => ['persons' => 3, 'totalPrice' => 0.0, 'totalPriceFormatted' => '0,00']],
        ]];

        $this->availabilityServiceMock($service, $availability);
        $this->roomMock($service, [101]);

        $result = $service->buildSelectionPreview(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
            3,
            1,
            ['category:1' => [3 => 1]],
            new Request(),
            [],
            [1 => 2, 2 => 1],
        );

        self::assertCount(1, $result['roomReservations']);
        self::assertSame([1 => 2, 2 => 1], $result['roomReservations'][0]->getGuestCounts());
    }

    public function testGuestCountsDistributedAcrossMultipleRoomsWithAdultPriority(): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT, true);
        $child = $this->makeCategory(2, GuestStatisticalGroup::CHILD, true);

        $service = $this->makeService([$adult, $child]);

        $availability = [[
            'typeKey' => 'category:1',
            'typeLabel' => 'Double',
            'typeDescription' => null,
            'maxGuests' => 2,
            'availableCount' => 2,
            'roomIds' => [101, 102],
            'subsidiaryIds' => [1],
            'occupancyOptions' => [2 => ['persons' => 2, 'totalPrice' => 0.0, 'totalPriceFormatted' => '0,00']],
        ]];

        $this->availabilityServiceMock($service, $availability);
        $this->roomMock($service, [101, 102]);

        // 2 adults + 2 children, 2 rooms × 2 persons each
        // Expected: each room gets 1 adult + 1 child (adult-priority).
        $result = $service->buildSelectionPreview(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
            4,
            2,
            ['category:1' => [2 => 2]],
            new Request(),
            [],
            [1 => 2, 2 => 2],
        );

        self::assertCount(2, $result['roomReservations']);
        self::assertSame([1 => 1, 2 => 1], $result['roomReservations'][0]->getGuestCounts());
        self::assertSame([1 => 1, 2 => 1], $result['roomReservations'][1]->getGuestCounts());
    }

    public function testNonOccupancyCategoryAttachesToFirstRoomOnly(): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT, true);
        $infant = $this->makeCategory(3, GuestStatisticalGroup::INFANT, false);

        $service = $this->makeService([$adult, $infant]);

        $availability = [[
            'typeKey' => 'category:1',
            'typeLabel' => 'Single',
            'typeDescription' => null,
            'maxGuests' => 1,
            'availableCount' => 2,
            'roomIds' => [101, 102],
            'subsidiaryIds' => [1],
            'occupancyOptions' => [1 => ['persons' => 1, 'totalPrice' => 0.0, 'totalPriceFormatted' => '0,00']],
        ]];

        $this->availabilityServiceMock($service, $availability);
        $this->roomMock($service, [101, 102]);

        // 2 adults across 2 rooms (1 each) + 1 infant (non-occupancy → room 0).
        $result = $service->buildSelectionPreview(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
            2,
            2,
            ['category:1' => [1 => 2]],
            new Request(),
            [],
            [1 => 2, 3 => 1],
        );

        self::assertSame([1 => 1, 3 => 1], $result['roomReservations'][0]->getGuestCounts());
        self::assertSame([1 => 1], $result['roomReservations'][1]->getGuestCounts());
    }

    public function testTouristTaxLinesAppearInPreview(): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT, true);

        $touristTaxService = $this->createStub(TouristTaxService::class);
        $touristTaxService->method('calculateForReservation')->willReturnCallback(
            function (Reservation $r) {
                if ($r->getCountForCategory(1) <= 0) {
                    return [];
                }

                return [new \App\Dto\TouristTaxBreakdown(
                    taxId: 1, taxName: 'Kurtaxe', categoryId: 1, categoryName: 'Erw.',
                    pricePerNight: 3.0, nights: 2, count: 2,
                    reportGroup: null, taxRate: null, revenueAccount: null, includesVat: false,
                )];
            }
        );

        $service = $this->makeService([$adult], touristTaxService: $touristTaxService);

        $availability = [[
            'typeKey' => 'category:1',
            'typeLabel' => 'Double',
            'typeDescription' => null,
            'maxGuests' => 2,
            'availableCount' => 1,
            'roomIds' => [101],
            'subsidiaryIds' => [1],
            'occupancyOptions' => [2 => ['persons' => 2, 'totalPrice' => 0.0, 'totalPriceFormatted' => '0,00']],
        ]];

        $this->availabilityServiceMock($service, $availability);
        $this->roomMock($service, [101]);

        $result = $service->buildSelectionPreview(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
            2,
            1,
            ['category:1' => [2 => 1]],
            new Request(),
            [],
            [1 => 2],
        );

        self::assertSame(12.0, $result['touristTaxTotal']); // 3.0 × 2 nights × 2 guests
        self::assertCount(1, $result['touristTaxLines']);
        self::assertStringContainsString('Kurtaxe', $result['touristTaxLines'][0]['label']);
    }

    /**
     * @param GuestCategory[] $categories
     * @param Reservation[]&array<int,Reservation> $reservationCapture
     */
    private function makeService(
        array $categories,
        ?TouristTaxService $touristTaxService = null,
        ?array &$reservationCapture = null,
    ): PublicBookingService {
        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getReservationOrigin')->willReturn(null);

        $invoiceService = $this->createStub(InvoiceService::class);
        $invoiceService->method('buildAppartmentPositions')->willReturn([new InvoiceAppartment()]);
        $invoiceService->method('buildApartmentModifierPositions')->willReturn([]);
        // calculateSums fills the by-ref outputs; emulate by writing zero totals.
        $invoiceService->method('calculateSums')->willReturnCallback(
            function ($apps, $poss, &$vats, &$brutto, &$netto, &$apartmentTotal, &$miscTotal) {
                $vats = [];
                $brutto = $netto = $apartmentTotal = $miscTotal = 0.0;
            }
        );

        $catRepo = $this->createStub(GuestCategoryRepository::class);
        $catRepo->method('findAll')->willReturn($categories);

        $appartmentRepo = $this->createStub(AppartmentRepository::class);
        $availabilityService = $this->createStub(PublicAvailabilityService::class);

        $service = new PublicBookingService(
            $this->createStub(EntityManagerInterface::class),
            $appartmentRepo,
            $configService,
            $availabilityService,
            $invoiceService,
            $this->createStub(TemplatesService::class),
            $this->createStub(MailService::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(PublicPricingService::class),
            $catRepo,
            $touristTaxService,
        );

        // expose stubs back to caller
        $this->_currentAvailabilityStub = $availabilityService;
        $this->_currentAppartmentRepo = $appartmentRepo;

        return $service;
    }

    private PublicAvailabilityService $_currentAvailabilityStub;
    private AppartmentRepository $_currentAppartmentRepo;

    private function availabilityServiceMock(PublicBookingService $svc, array $availability): void
    {
        // The stub from makeService captured the availability service.
        $this->_currentAvailabilityStub->method('getAvailability')->willReturn($availability);
    }

    private function roomMock(PublicBookingService $svc, array $roomIds): void
    {
        $rooms = [];
        foreach ($roomIds as $id) {
            $room = new Appartment();
            $cat = new RoomCategory();
            $room->setRoomCategory($cat);
            $room->setBedsMax(3);
            $room->setNumber('R'.$id);
            $room->setDescription('Room '.$id);
            $sub = new Subsidiary();
            (new \ReflectionProperty(Appartment::class, 'id'))->setValue($room, $id);
            $room->setObject($sub);
            $rooms[$id] = $room;
        }
        $this->_currentAppartmentRepo->method('findByIdsWithRelations')->willReturnCallback(
            fn (array $ids) => array_values(array_intersect_key($rooms, array_flip($ids)))
        );
    }

    private function makeCategory(int $id, GuestStatisticalGroup $group, bool $countsInOccupancy): GuestCategory
    {
        $c = new GuestCategory();
        $c->setName('cat'.$id);
        $c->setAcronym('C'.$id);
        $c->setStatisticalGroup($group);
        $c->setIsCountedInOccupancy($countsInOccupancy);
        (new \ReflectionProperty(GuestCategory::class, 'id'))->setValue($c, $id);

        return $c;
    }
}
