<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Service\ReservationOriginService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ReservationOriginServiceTest extends TestCase
{
    public function testReadsAndNormalizesColorFromForm(): void
    {
        $service = new ReservationOriginService(
            $this->createStub(EntityManagerInterface::class),
            new RequestStack()
        );
        $request = new Request([], [
            'name-new' => ' Direct ',
            'color-new' => '#A1B2C3',
            'color-enabled-new' => '1',
        ]);

        $origin = $service->getOriginFromForm($request);

        self::assertSame('Direct', $origin->getName());
        self::assertSame('#a1b2c3', $origin->getColor());
    }

    public function testColorIsNullWhenIndicatorIsDisabled(): void
    {
        $service = new ReservationOriginService(
            $this->createStub(EntityManagerInterface::class),
            new RequestStack()
        );
        $request = new Request([], [
            'name-new' => 'Direct',
            'color-new' => '#a1b2c3',
        ]);

        $origin = $service->getOriginFromForm($request);

        self::assertNull($origin->getColor());
    }

    public function testInvalidEnabledColorIsNotStored(): void
    {
        $service = new ReservationOriginService(
            $this->createStub(EntityManagerInterface::class),
            new RequestStack()
        );
        $request = new Request([], [
            'name-new' => 'Direct',
            'color-new' => 'red; display: none',
            'color-enabled-new' => '1',
        ]);

        $origin = $service->getOriginFromForm($request);

        self::assertNull($origin->getColor());
    }

    public function testUnusedOriginIsDeletedWithoutRequiringASession(): void
    {
        $origin = new ReservationOrigin();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('remove')->with($origin);
        $em->expects(self::once())->method('flush');
        $service = new ReservationOriginService($em, new RequestStack());

        self::assertTrue($service->deleteOrigin($origin));
    }

    public function testUsedOriginIsKeptAndProducesTheExistingWarning(): void
    {
        $origin = new ReservationOrigin();
        $origin->addReservation(new Reservation());
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('remove');
        $em->expects(self::never())->method('flush');
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);
        $service = new ReservationOriginService($em, $stack);

        self::assertFalse($service->deleteOrigin($origin));
        self::assertSame(['reservationorigin.flash.delete.inuse.reservations'], $session->getFlashBag()->get('warning'));
    }

    public function testUnsupportedSessionFailsClearlyWithoutDeletingAUsedOrigin(): void
    {
        $origin = new ReservationOrigin();
        $origin->addReservation(new Reservation());
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('remove');
        $em->expects(self::never())->method('flush');
        $request = new Request();
        $request->setSession($this->createStub(SessionInterface::class));
        $stack = new RequestStack();
        $stack->push($request);
        $service = new ReservationOriginService($em, $stack);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Reservation origin feedback requires a session supporting flash messages.');

        $service->deleteOrigin($origin);
    }
}
