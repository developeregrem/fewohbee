<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Controller\ReservationServiceController;
use App\Service\ReservationObject;
use App\Service\ReservationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ReservationServiceControllerOptionsTest extends TestCase
{
    public function testModifyOptionsUsesGuestCountsAsSourceForPersons(): void
    {
        $reservationObject = new ReservationObject(42, '2026-08-08', '2026-08-10', 1, 2);
        $session = new Session(new MockArraySessionStorage());
        $session->set('reservationInCreation', [$reservationObject]);
        $request = Request::create('/reservation/appartments/modify/options', 'POST', [
            'appartmentid' => '0',
            'guestCounts' => '{"7":1}',
            'persons' => '2',
            'status' => '3',
        ]);
        $request->setSession($session);

        $reservationService = $this->createMock(ReservationService::class);
        $reservationService->expects(self::once())
            ->method('computePersonsFromCounts')
            ->with([7 => 1])
            ->willReturn(1);

        $controller = new ReservationServiceController();
        $controller->modifyAppartmentOptionsAction(
            $this->makeKernel(),
            $this->makeRequestStack($request),
            $request,
            $reservationService,
        );

        self::assertSame([7 => 1], $reservationObject->getGuestCounts());
        self::assertSame(1, $reservationObject->getPersons());
        self::assertSame('3', $reservationObject->getReservationStatus());
    }

    public function testModifyOptionsHandlesMissingSessionSelection(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/reservation/appartments/modify/options', 'POST', [
            'appartmentid' => '0',
            'guestCounts' => '{"7":1}',
        ]);
        $request->setSession($session);

        $controller = new ReservationServiceController();
        $response = $controller->modifyAppartmentOptionsAction(
            $this->makeKernel(),
            $this->makeRequestStack($request),
            $request,
            $this->createStub(ReservationService::class),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['reservation.no.selected.appartments'],
            $session->getFlashBag()->peek('warning'),
        );
    }

    private function makeKernel(): HttpKernelInterface
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->expects(self::once())
            ->method('handle')
            ->with(self::isInstanceOf(Request::class), HttpKernelInterface::SUB_REQUEST)
            ->willReturn(new Response());

        return $kernel;
    }

    private function makeRequestStack(Request $request): RequestStack
    {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return $requestStack;
    }
}
