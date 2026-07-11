<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\CalendarSyncImport;
use App\Repository\GuestCategoryRepository;
use App\Repository\ReservationRepository;
use App\Service\CalendarImportService;
use App\Service\InvoiceService;
use App\Service\ReservationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ApartmentActivationTest extends TestCase
{
    public function testNewApartmentIsActiveByDefaultAndCanBeDeactivated(): void
    {
        $apartment = new Appartment();

        self::assertTrue($apartment->isActive());

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

        $service = new CalendarImportService(
            $this->createStub(EntityManagerInterface::class),
            $httpClient,
            new ArrayAdapter(),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ReservationRepository::class),
        );

        $import = (new CalendarSyncImport())
            ->setApartment((new Appartment())->setActive(false))
            ->setIsActive(true);

        $service->syncImport($import);
    }
}
