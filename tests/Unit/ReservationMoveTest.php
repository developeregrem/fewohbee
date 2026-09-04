<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Enum\GuestStatisticalGroup;
use App\Entity\GuestCategory;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Repository\GuestCategoryRepository;
use App\Service\AvailabilityService;
use App\Service\InvoiceService;
use App\Service\ReservationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ReservationMoveTest extends TestCase
{
    public function testMoveUsesAvailabilityServiceAndPersistsAvailableReservation(): void
    {
        $reservation = $this->reservation();
        $targetApartment = (new Appartment())->setId(22);
        $start = new \DateTime('2026-08-10');
        $end = new \DateTime('2026-08-13');

        $availability = $this->createMock(AvailabilityService::class);
        $availability->expects(self::once())
            ->method('isRoomAvailable')
            ->with($targetApartment, $start, $end, 2, $reservation)
            ->willReturn(true);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with($reservation);
        $em->expects(self::once())->method('flush');

        $service = $this->service($em, $availability);

        self::assertTrue($service->moveReservation($reservation, $targetApartment, $start, $end));
        self::assertSame('2026-08-10', $reservation->getStartDate()->format('Y-m-d'));
        self::assertSame('2026-08-13', $reservation->getEndDate()->format('Y-m-d'));
        self::assertSame($targetApartment, $reservation->getAppartment());
    }

    public function testMoveLeavesReservationUntouchedOnConflict(): void
    {
        $reservation = $this->reservation();
        $originalApartment = $reservation->getAppartment();
        $targetApartment = (new Appartment())->setId(22);

        $availability = $this->createMock(AvailabilityService::class);
        $availability->expects(self::once())
            ->method('isRoomAvailable')
            ->willReturn(false);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $service = $this->service($em, $availability);

        self::assertFalse($service->moveReservation(
            $reservation,
            $targetApartment,
            new \DateTime('2026-08-10'),
            new \DateTime('2026-08-13'),
        ));
        self::assertSame('2026-07-01', $reservation->getStartDate()->format('Y-m-d'));
        self::assertSame('2026-07-04', $reservation->getEndDate()->format('Y-m-d'));
        self::assertSame($originalApartment, $reservation->getAppartment());
    }

    public function testManualUpdateDoesNotFlushPartialChangesWhenMoveIsUnavailable(): void
    {
        $reservation = $this->reservation();
        $originalApartment = $reservation->getAppartment();
        $originalStatus = new ReservationStatus();
        $reservation->setReservationStatus($originalStatus);
        $reservation->setGuestCounts([1 => 2]);
        $reservation->setAdultRuleOverride(false);
        $reservation->setKurtaxeWaived(true);

        $targetApartment = (new Appartment())->setId(22);
        $submittedStatus = new ReservationStatus();
        $apartmentRepository = $this->createStub(EntityRepository::class);
        $apartmentRepository->method('find')->willReturn($targetApartment);
        $statusRepository = $this->createStub(EntityRepository::class);
        $statusRepository->method('find')->willReturn($submittedStatus);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => match ($class) {
            Appartment::class => $apartmentRepository,
            ReservationStatus::class => $statusRepository,
        });
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $availability = $this->createMock(AvailabilityService::class);
        $availability->expects(self::once())
            ->method('isRoomAvailable')
            ->with($targetApartment, self::isInstanceOf(\DateTime::class), self::isInstanceOf(\DateTime::class), 3, $reservation)
            ->willReturn(false);

        $request = new Request([], [
            'aid' => '22',
            'status' => '2',
            'from' => '2026-08-10',
            'end' => '2026-08-13',
            'guestCounts' => '{}',
            'persons' => '3',
            'adultRuleOverride' => '1',
        ]);

        $service = $this->service($em, $availability);

        self::assertFalse($service->updateReservation($request, $reservation));
        self::assertSame($originalApartment, $reservation->getAppartment());
        self::assertSame($originalStatus, $reservation->getReservationStatus());
        self::assertSame([1 => 2], $reservation->getGuestCounts());
        self::assertSame(2, $reservation->getPersons());
        self::assertFalse($reservation->isAdultRuleOverride());
        self::assertTrue($reservation->isKurtaxeWaived());
    }

    public function testManualUpdateCanReduceOccupancyAndMoveFromDoubleToSingleRoom(): void
    {
        $reservation = $this->reservation();
        $status = new ReservationStatus();
        $reservation->setReservationStatus($status);
        $reservation->setGuestCounts([1 => 2]);

        $singleRoom = (new Appartment())->setId(22);
        $singleRoom->setBedsMax(1);
        $apartmentRepository = $this->createStub(EntityRepository::class);
        $apartmentRepository->method('find')->willReturn($singleRoom);
        $statusRepository = $this->createStub(EntityRepository::class);
        $statusRepository->method('find')->willReturn($status);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => match ($class) {
            Appartment::class => $apartmentRepository,
            ReservationStatus::class => $statusRepository,
        });
        $em->expects(self::once())->method('persist')->with($reservation);
        $em->expects(self::once())->method('flush');

        $adult = new GuestCategory();
        (new \ReflectionProperty(GuestCategory::class, 'id'))->setValue($adult, 1);
        $adult->setName('Adults');
        $adult->setAcronym('A');
        $adult->setIsCountedInOccupancy(true);
        $adult->setStatisticalGroup(GuestStatisticalGroup::ADULT);
        $guestCategoryRepository = $this->createStub(GuestCategoryRepository::class);
        $guestCategoryRepository->method('findBy')->willReturn([$adult]);

        $availability = $this->createMock(AvailabilityService::class);
        $availability->expects(self::once())
            ->method('isRoomAvailable')
            ->with($singleRoom, self::isInstanceOf(\DateTime::class), self::isInstanceOf(\DateTime::class), 1, $reservation)
            ->willReturn(true);

        $request = new Request([], [
            'aid' => '22',
            'status' => '2',
            'from' => '2026-08-10',
            'end' => '2026-08-13',
            'guestCounts' => '{"1":1}',
            'persons' => '1',
        ]);

        $service = $this->service($em, $availability, $guestCategoryRepository);

        self::assertTrue($service->updateReservation($request, $reservation));
        self::assertSame($singleRoom, $reservation->getAppartment());
        self::assertSame([1 => 1], $reservation->getGuestCounts());
        self::assertSame(1, $reservation->getPersons());
    }

    private function reservation(): Reservation
    {
        $reservation = new Reservation();
        $reservation->setId(7);
        $reservation->setStartDate(new \DateTime('2026-07-01'));
        $reservation->setEndDate(new \DateTime('2026-07-04'));
        $reservation->setPersons(2);
        $reservation->setAppartment((new Appartment())->setId(11));

        return $reservation;
    }

    private function service(
        EntityManagerInterface $em,
        AvailabilityService $availability,
        ?GuestCategoryRepository $guestCategoryRepository = null,
    ): ReservationService {
        return new ReservationService(
            $em,
            new RequestStack(),
            $this->createStub(InvoiceService::class),
            $this->createStub(EventDispatcherInterface::class),
            $guestCategoryRepository ?? $this->createStub(GuestCategoryRepository::class),
            $availability,
            new \App\Service\ReservationPeriodService(),
        );
    }
}
