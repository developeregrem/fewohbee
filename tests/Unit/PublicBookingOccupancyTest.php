<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Exception\PublicBookingException;
use App\Repository\AppartmentRepository;
use App\Service\InvoiceService;
use App\Service\OnlineBookingConfigService;
use App\Service\PublicAvailabilityService;
use App\Service\PublicBookingService;
use App\Service\BookingNotificationService;
use App\Service\MailService;
use App\Service\TemplatesService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tests for the occupancy-based selection and validation in PublicBookingService.
 */
final class PublicBookingOccupancyTest extends TestCase
{
    private PublicBookingService $service;
    private AppartmentRepository $appartmentRepository;
    private PublicAvailabilityService $availabilityService;

    protected function setUp(): void
    {
        $this->appartmentRepository = $this->createStub(AppartmentRepository::class);
        $this->availabilityService = $this->createStub(PublicAvailabilityService::class);

        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getReservationOrigin')->willReturn(null);

        $this->service = new PublicBookingService(
            $this->createStub(EntityManagerInterface::class),
            $this->appartmentRepository,
            $configService,
            $this->availabilityService,
            $this->createStub(InvoiceService::class),
            $this->createStub(TemplatesService::class),
            $this->createStub(MailService::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(BookingNotificationService::class),
        );
    }

    /** Empty selection when qty=0 should pass through without error. */
    public function testBuildSelectionPreviewWithEmptySelectionReturnsAvailability(): void
    {
        $availability = [
            [
                'typeKey' => 'category:1',
                'typeLabel' => 'Double Room',
                'typeDescription' => null,
                'maxGuests' => 2,
                'availableCount' => 2,
                'roomIds' => [1, 2],
                'subsidiaryIds' => [1],
                'occupancyOptions' => [
                    1 => ['persons' => 1, 'totalPrice' => 80.0, 'totalPriceFormatted' => '80,00'],
                    2 => ['persons' => 2, 'totalPrice' => 120.0, 'totalPriceFormatted' => '120,00'],
                ],
            ],
        ];

        $this->availabilityService->method('getAvailability')->willReturn($availability);

        $result = $this->service->buildSelectionPreview(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
            2,
            1,
            [],
            new Request()
        );

        self::assertSame($availability, $result['availability']);
        self::assertSame([], $result['selected']);
    }

    /** Persons sum mismatch should throw exception. */
    public function testValidationThrowsOnPersonsSumMismatch(): void
    {
        $availability = [
            [
                'typeKey' => 'category:1',
                'typeLabel' => 'Double Room',
                'typeDescription' => null,
                'maxGuests' => 2,
                'availableCount' => 2,
                'roomIds' => [1, 2],
                'subsidiaryIds' => [1],
                'occupancyOptions' => [
                    1 => ['persons' => 1, 'totalPrice' => 80.0, 'totalPriceFormatted' => '80,00'],
                    2 => ['persons' => 2, 'totalPrice' => 120.0, 'totalPriceFormatted' => '120,00'],
                ],
            ],
        ];

        $this->availabilityService->method('getAvailability')->willReturn($availability);

        // 3 persons requested but selection is 1 room with 2 persons = 2 total → mismatch
        $this->expectException(PublicBookingException::class);
        $this->expectExceptionMessage('online_booking.error.persons_sum_mismatch');

        $this->service->buildSelectionPreview(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
            3,
            1,
            ['category:1' => [2 => 1]],
            new Request()
        );
    }

    /** Occupancy level without price should throw exception. */
    public function testValidationThrowsOnOccupancyWithoutPrice(): void
    {
        $availability = [
            [
                'typeKey' => 'category:1',
                'typeLabel' => 'Double Room',
                'typeDescription' => null,
                'maxGuests' => 2,
                'availableCount' => 2,
                'roomIds' => [1, 2],
                'subsidiaryIds' => [1],
                'occupancyOptions' => [
                    // Only persons=2 has a price, persons=1 does not
                    2 => ['persons' => 2, 'totalPrice' => 120.0, 'totalPriceFormatted' => '120,00'],
                ],
            ],
        ];

        $this->availabilityService->method('getAvailability')->willReturn($availability);

        $this->expectException(PublicBookingException::class);
        $this->expectExceptionMessage('online_booking.error.occupancy_no_price');

        $this->service->buildSelectionPreview(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
            1,
            1,
            ['category:1' => [1 => 1]], // persons=1 has no price
            new Request()
        );
    }

    /** Exceeding available count should throw. */
    public function testValidationThrowsOnExceedingAvailability(): void
    {
        $availability = [
            [
                'typeKey' => 'category:1',
                'typeLabel' => 'Single Room',
                'typeDescription' => null,
                'maxGuests' => 1,
                'availableCount' => 1,
                'roomIds' => [1],
                'subsidiaryIds' => [1],
                'occupancyOptions' => [
                    1 => ['persons' => 1, 'totalPrice' => 60.0, 'totalPriceFormatted' => '60,00'],
                ],
            ],
        ];

        $this->availabilityService->method('getAvailability')->willReturn($availability);

        $this->expectException(PublicBookingException::class);
        $this->expectExceptionMessage('online_booking.error.qty_exceeds_availability');

        $this->service->buildSelectionPreview(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
            2,
            2,
            ['category:1' => [1 => 2]], // only 1 available
            new Request()
        );
    }

    /** Valid selection with correct person distribution should create reservations. */
    public function testValidSelectionCreatesCorrectReservations(): void
    {
        $subsidiary = $this->createStub(Subsidiary::class);
        $subsidiary->method('getId')->willReturn(1);

        $category = new RoomCategory();
        $room1 = new Appartment();
        $room1->setId(1);
        $room1->setBedsMax(2);
        $room1->setNumber('101');
        $room1->setDescription('DZ');
        $room1->setRoomCategory($category);
        $room1->setObject($subsidiary);

        $room2 = new Appartment();
        $room2->setId(2);
        $room2->setBedsMax(3);
        $room2->setNumber('102');
        $room2->setDescription('DZ+');
        $room2->setRoomCategory($category);
        $room2->setObject($subsidiary);

        $availability = [
            [
                'typeKey' => 'category:1',
                'typeLabel' => 'Multi Room',
                'typeDescription' => null,
                'maxGuests' => 3,
                'availableCount' => 2,
                'roomIds' => [1, 2],
                'subsidiaryIds' => [1],
                'occupancyOptions' => [
                    1 => ['persons' => 1, 'totalPrice' => 50.0, 'totalPriceFormatted' => '50,00'],
                    2 => ['persons' => 2, 'totalPrice' => 80.0, 'totalPriceFormatted' => '80,00'],
                    3 => ['persons' => 3, 'totalPrice' => 100.0, 'totalPriceFormatted' => '100,00'],
                ],
            ],
        ];

        $this->availabilityService->method('getAvailability')->willReturn($availability);
        $this->appartmentRepository->method('findByIdsWithRelations')
            ->willReturn([$room1, $room2]);

        $invoiceService = $this->createStub(InvoiceService::class);
        $invoiceService->method('buildAppartmentPositions')->willReturn([]);
        $invoiceService->method('calculateSums')->willReturnCallback(function ($apps, $poss, &$vats, &$brutto, &$netto, &$singleTotal, &$miscTotal) {
            $singleTotal = 0.0;
        });

        // Rebuild service with this invoice service
        $configService = $this->createStub(OnlineBookingConfigService::class);
        $configService->method('getReservationOrigin')->willReturn(null);

        $service = new PublicBookingService(
            $this->createStub(EntityManagerInterface::class),
            $this->appartmentRepository,
            $configService,
            $this->availabilityService,
            $invoiceService,
            $this->createStub(TemplatesService::class),
            $this->createStub(MailService::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(BookingNotificationService::class),
        );

        // Select: 1x with 1 person, 1x with 3 persons = 4 total, 2 rooms
        $result = $service->buildSelectionPreview(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
            4,
            2,
            ['category:1' => [1 => 1, 3 => 1]],
            new Request()
        );

        $reservations = $result['roomReservations'];
        self::assertCount(2, $reservations);

        // First room gets 1 person, second room gets 3 persons
        self::assertSame(1, $reservations[0]->getPersons());
        self::assertSame(3, $reservations[1]->getPersons());
    }
}
