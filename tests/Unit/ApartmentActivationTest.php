<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\CalendarSyncImport;
use App\Repository\AppartmentRepository;
use App\Repository\CalendarSyncImportRepository;
use App\Repository\GuestCategoryRepository;
use App\Repository\ReservationRepository;
use App\Repository\RoomBlockRepository;
use App\Service\AvailabilityService;
use App\Service\Calendar\Sync\CalendarImportSummaryMatcher;
use App\Service\Calendar\Sync\Ics\IcsFeedClient;
use App\Service\Calendar\Sync\Ics\IcsOccurrenceReader;
use App\Service\Calendar\Sync\ImportedReservationSynchronizer;
use App\Service\Calendar\Sync\ReservationCalendarImportService;
use App\Service\InvoiceService;
use App\Service\ReservationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Verify active-state defaults and guards shared by apartments and calendar imports. */
final class ApartmentActivationTest extends TestCase
{
    public function testNewApartmentIsActiveByDefaultAndCanBeDeactivated(): void
    {
        $apartment = new Appartment();

        self::assertTrue($apartment->isActive());
        self::assertSame($apartment, $apartment->getCalendarSync()->getApartment());
        self::assertInstanceOf(\Symfony\Component\Uid\Uuid::class, $apartment->getCalendarSync()->getUuid());

        $returnedApartment = $apartment->setActive(false);

        self::assertSame($apartment, $returnedApartment);
        self::assertFalse($apartment->isActive());
    }

    public function testInactiveApartmentIsNeverAvailableForReservation(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getRepository');

        $service = new ReservationService(
            $entityManager,
            new RequestStack(),
            $this->createStub(InvoiceService::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(GuestCategoryRepository::class),
            new AvailabilityService(
                $this->createStub(ReservationRepository::class),
                $this->createStub(RoomBlockRepository::class),
                $this->createStub(AppartmentRepository::class),
            ),
            new \App\Service\ReservationPeriodService(),
        );

        $apartment = (new Appartment())->setActive(false);

        self::assertFalse($service->isApartmentAvailable(
            new \DateTimeImmutable('2026-07-11'),
            new \DateTimeImmutable('2026-07-12'),
            $apartment,
            1,
        ));
    }

    public function testIcalImportDoesNotRequestFeedForInactiveApartment(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request');
        $entityManager = $this->createStub(EntityManagerInterface::class);

        $service = new ReservationCalendarImportService(
            $entityManager,
            $this->createStub(CalendarSyncImportRepository::class),
            new IcsFeedClient($httpClient),
            new ArrayAdapter(),
            $this->createStub(TranslatorInterface::class),
            new IcsOccurrenceReader(),
            new ImportedReservationSynchronizer(
                $entityManager,
                $this->createStub(EventDispatcherInterface::class),
                $this->createStub(ReservationRepository::class),
                $this->createStub(AvailabilityService::class),
                $this->createStub(GuestCategoryRepository::class),
                $this->createStub(ReservationService::class),
            ),
            new CalendarImportSummaryMatcher(),
        );

        $import = (new CalendarSyncImport())
            ->setApartment((new Appartment())->setActive(false))
            ->setIsActive(true);

        $service->syncImport($import);
    }
}
