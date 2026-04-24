<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Price;
use App\Entity\Reservation;
use App\Service\InvoiceService;
use App\Service\PriceService;
use App\Service\ReservationService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ReservationServiceMiscDefaultActivationTest extends TestCase
{
    public function testMiscPricesInCreationUsesDefaultActivationAndActiveFlag(): void
    {
        $priceEnabledByDefault = $this->createPrice(1, true, true);
        $priceDisabledByDefault = $this->createPrice(2, true, false);
        $priceInactive = $this->createPrice(3, false, true);

        $priceService = $this->createMock(PriceService::class);
        $priceService
            ->expects(self::once())
            ->method('getUniquePricesForReservations')
            ->with(self::isArray(), 1)
            ->willReturn(new ArrayCollection([
                $priceEnabledByDefault,
                $priceDisabledByDefault,
                $priceInactive,
            ]));

        $invoiceService = $this->createMock(InvoiceService::class);

        $requestStack = $this->createRequestStack();
        $invoiceService
            ->expects(self::once())
            ->method('prefillMiscPositionsWithReservations')
            ->with(self::isArray(), $requestStack, true)
            ->willReturnCallback(static function (array $reservations, RequestStack $stack): void {
                $stack->getSession()->set('invoicePositionsMiscellaneous', []);
            });

        $reservationService = new ReservationService(
            $this->createStub(EntityManagerInterface::class),
            $requestStack,
            $invoiceService,
            $this->createStub(EventDispatcherInterface::class),
        );

        $reservationService->getMiscPricesInCreation(
            $invoiceService,
            [new Reservation()],
            $priceService,
            $requestStack
        );

        $selectedPrices = $requestStack->getSession()->get('reservatioInCreationPrices', new ArrayCollection());
        self::assertCount(1, $selectedPrices);
        self::assertSame(1, $selectedPrices[0]->getId());
    }

    private function createRequestStack(): RequestStack
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return $requestStack;
    }

    private function createPrice(int $id, bool $active, bool $defaultActive): Price
    {
        $price = new Price();
        $price->setId($id);
        $price->setActive($active);
        $price->setIsDefaultActiveInReservationCreation($defaultActive);

        return $price;
    }
}
