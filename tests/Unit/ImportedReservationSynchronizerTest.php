<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dto\Ics\IcsOccurrence;
use App\Entity\Appartment;
use App\Entity\CalendarSyncImport;
use App\Entity\GuestCategory;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Event\CalendarImportBookingCreatedEvent;
use App\Repository\GuestCategoryRepository;
use App\Repository\ReservationRepository;
use App\Service\AvailabilityService;
use App\Service\Calendar\Sync\ImportedReservationSynchronizer;
use App\Service\Calendar\Sync\ReservationImportOutcome;
use App\Service\ReservationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** Verify defaults and side effects applied to newly imported reservations. */
final class ImportedReservationSynchronizerTest extends TestCase
{
    public function testConflictImportUsesBedCountGuestCountsAndDispatchesCreatedEvent(): void
    {
        $apartment = new Appartment();
        $apartment->setBedsMax(3);

        $import = (new CalendarSyncImport())
            ->setApartment($apartment)
            ->setConflictStrategy(CalendarSyncImport::CONFLICT_MARK)
            ->setReservationOrigin(new ReservationOrigin())
            ->setReservationStatus(new ReservationStatus());

        $repository = $this->createStub(ReservationRepository::class);
        $repository->method('findOneByRefUidAndImport')->willReturn(null);

        $availability = $this->createStub(AvailabilityService::class);
        $availability->method('getConflictingReservations')->willReturn([new Reservation()]);
        $availability->method('getConflictingBlocks')->willReturn([]);

        $adult = $this->createStub(GuestCategory::class);
        $adult->method('getId')->willReturn(7);
        $guestCategories = $this->createStub(GuestCategoryRepository::class);
        $guestCategories->method('findDefaultAdult')->willReturn($adult);

        $reservationService = $this->createMock(ReservationService::class);
        $reservationService
            ->expects(self::once())
            ->method('applyGuestCounts')
            ->with(self::isInstanceOf(Reservation::class), [7 => 3])
            ->willReturnCallback(static function (Reservation $reservation, array $counts): void {
                $reservation->setGuestCounts($counts);
                $reservation->setPersons(3);
            });

        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted = $entity;
            });
        $entityManager->expects(self::once())->method('flush');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $event): bool {
                return $event instanceof CalendarImportBookingCreatedEvent;
            }))
            ->willReturnArgument(0);

        $service = new ImportedReservationSynchronizer(
            $entityManager,
            $dispatcher,
            $repository,
            $availability,
            $guestCategories,
            $reservationService,
        );

        $outcome = $service->synchronize($import, new IcsOccurrence(
            uid: 'portal-uid',
            summary: 'Reservation',
            description: 'Imported note',
            start: new \DateTimeImmutable('+2 days'),
            end: new \DateTimeImmutable('+4 days'),
            allDay: true,
        ));

        self::assertSame(ReservationImportOutcome::Synchronized, $outcome);
        self::assertInstanceOf(Reservation::class, $persisted);
        self::assertSame(3, $persisted->getPersons());
        self::assertSame([7 => 3], $persisted->getGuestCounts());
        self::assertTrue($persisted->isConflict());
        self::assertSame('Imported note', $persisted->getRemark());
    }
}
