<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\CustomerAddresses;
use App\Entity\Reservation;
use App\Entity\Template;
use App\Repository\GuestCategoryRepository;
use App\Service\AvailabilityService;
use App\Service\InvoiceService;
use App\Service\ReservationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Covers the reservation context assembled for user-authored templates.
 */
final class ReservationTemplateRenderParamsTest extends TestCase
{
    public function testReservationWithoutBookerUsesAnEmptyAddress(): void
    {
        $invoiceService = $this->createStub(InvoiceService::class);
        $invoiceService->method('buildMiscPositions')->willReturn([]);
        $invoiceService->method('buildAppartmentPositions')->willReturn([]);

        $service = new ReservationService(
            $this->createStub(EntityManagerInterface::class),
            new RequestStack(),
            $invoiceService,
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(GuestCategoryRepository::class),
            $this->createStub(AvailabilityService::class),
        );
        $reservation = new Reservation();

        $params = $service->buildTemplateRenderParams(new Template(), [$reservation]);

        self::assertSame($reservation, $params['reservation1']);
        self::assertInstanceOf(CustomerAddresses::class, $params['address']);
    }
}
