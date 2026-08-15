<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dto\TouristTaxBreakdown;
use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\Enum\GuestStatisticalGroup;
use App\Event\OnlineBookingCreatedEvent;
use App\Entity\GuestCategory;
use App\Entity\InvoiceAppartment;
use App\Entity\OnlineBookingConfig;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Repository\AppartmentRepository;
use App\Repository\CustomerRepository;
use App\Repository\GuestCategoryRepository;
use App\Service\InvoiceService;
use App\Service\OnlineBookingConfigService;
use App\Service\PublicAvailabilityService;
use App\Service\PublicBookingService;
use App\Service\PublicPricingService;
use App\Service\TouristTaxService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Regression tests for the public booking grand total: the total returned by
 * createBooking() must match the preview total, including the tourist tax.
 */
final class PublicBookingGrandTotalTest extends TestCase
{
    public function testCreateBookingGrandTotalMatchesPreviewAndIncludesTouristTax(): void
    {
        $service = $this->makeService();

        $dateFrom = new \DateTimeImmutable('2026-06-01');
        $dateTo = new \DateTimeImmutable('2026-06-03');
        $selection = ['category:1' => [2 => 1]];
        $guestCounts = [1 => 2];

        $preview = $service->buildSelectionPreview($dateFrom, $dateTo, 2, 1, $selection, new Request(), [], $guestCounts);
        $booking = $service->createBooking($dateFrom, $dateTo, 2, 1, $selection, $this->booker(), new Request(), [], $guestCounts);

        // 3.0 per night × 2 nights × 2 guests = 12.0 tourist tax; room/extras totals are zero-stubbed.
        self::assertSame(12.0, $preview['touristTaxTotal']);
        self::assertSame(12.0, $booking['touristTaxTotal']);
        self::assertSame($preview['grandTotal'], $booking['grandTotal']);
        self::assertSame($preview['grandTotalFormatted'], $booking['grandTotalFormatted']);
        self::assertSame(12.0, $booking['grandTotal']);
        self::assertSame('12,00', $booking['grandTotalFormatted']);
    }

    public function testCreateBookingDispatchesWorkflowEventInsteadOfSendingMailDirectly(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $event): bool {
                return $event instanceof OnlineBookingCreatedEvent
                    && 1 === count($event->reservations)
                    && 'Muster' === $event->booker?->getLastname();
            }))
            ->willReturnArgument(0);

        $service = $this->makeService($eventDispatcher);
        $service->createBooking(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
            2,
            1,
            ['category:1' => [2 => 1]],
            $this->booker(),
            new Request(),
            [],
            [1 => 2],
        );
    }

    /** @return array<string, string> */
    private function booker(): array
    {
        return [
            'salutation' => 'Herr',
            'firstname' => 'Max',
            'lastname' => 'Muster',
            'email' => 'max@example.com',
            'address' => 'Musterweg 1',
            'zip' => '12345',
            'city' => 'Musterstadt',
            'country' => 'DE',
        ];
    }

    private function makeService(?EventDispatcherInterface $eventDispatcher = null): PublicBookingService
    {
        $config = new OnlineBookingConfig();
        $config->setEnabled(true);

        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getConfig')->willReturn($config);
        $configService->method('getReservationOrigin')->willReturn(new ReservationOrigin());
        $configService->method('getInquiryStatus')->willReturn(new ReservationStatus());
        $configService->method('getBookingStatus')->willReturn(new ReservationStatus());
        $invoiceService = $this->createStub(InvoiceService::class);
        $invoiceService->method('buildAppartmentPositions')->willReturn([new InvoiceAppartment()]);
        $invoiceService->method('buildApartmentModifierPositions')->willReturn([]);
        $invoiceService->method('calculateSums')->willReturnCallback(
            function ($apps, $poss, &$vats, &$brutto, &$netto, &$apartmentTotal, &$miscTotal) {
                $vats = [];
                $brutto = $netto = $apartmentTotal = $miscTotal = 0.0;
            }
        );

        $touristTaxService = $this->createStub(TouristTaxService::class);
        $touristTaxService->method('calculateForReservation')->willReturnCallback(
            function (Reservation $r) {
                if ($r->getCountForCategory(1) <= 0) {
                    return [];
                }

                return [new TouristTaxBreakdown(
                    taxId: 1, taxName: 'Kurtaxe', categoryId: 1, categoryName: 'Erw.',
                    pricePerNight: 3.0, nights: 2, count: 2,
                    reportGroup: null, taxRate: null, revenueAccount: null, includesVat: false,
                )];
            }
        );

        $adult = new GuestCategory();
        $adult->setName('Erwachsene');
        $adult->setAcronym('ERW');
        $adult->setStatisticalGroup(GuestStatisticalGroup::ADULT);
        $adult->setIsCountedInOccupancy(true);
        (new \ReflectionProperty(GuestCategory::class, 'id'))->setValue($adult, 1);

        $catRepo = $this->createStub(GuestCategoryRepository::class);
        $catRepo->method('findAll')->willReturn([$adult]);

        $customerRepo = $this->createStub(CustomerRepository::class);
        $customerRepo->method('findOneByEmailCaseInsensitive')->willReturn(null);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            fn (string $class) => match ($class) {
                Customer::class => $customerRepo,
                default => throw new \LogicException('Unexpected repository request: '.$class),
            }
        );

        $room = new Appartment();
        $room->setRoomCategory(new RoomCategory());
        $room->setBedsMax(3);
        $room->setNumber('R101');
        $room->setDescription('Room 101');
        $room->setObject(new Subsidiary());
        (new \ReflectionProperty(Appartment::class, 'id'))->setValue($room, 101);

        $appartmentRepo = $this->createStub(AppartmentRepository::class);
        $appartmentRepo->method('findByIdsWithRelations')->willReturn([$room]);

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
        $availabilityService = $this->createStub(PublicAvailabilityService::class);
        $availabilityService->method('getAvailability')->willReturn($availability);

        return new PublicBookingService(
            $em,
            $appartmentRepo,
            $configService,
            $availabilityService,
            $invoiceService,
            $eventDispatcher ?? $this->createStub(EventDispatcherInterface::class),
            $this->createStub(PublicPricingService::class),
            $catRepo,
            $touristTaxService,
        );
    }
}
