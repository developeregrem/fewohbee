<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\CalendarSyncImport;
use App\Entity\Customer;
use App\Entity\Enum\NotificationSeverity;
use App\Entity\Notification;
use App\Entity\Reservation;
use App\Repository\NotificationRepository;
use App\Service\NotificationCenterService;
use App\Notification\NotificationProviderRegistry;
use App\Workflow\Action\CreateInAppNotificationAction;
use App\Workflow\WorkflowSkippedException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CreateInAppNotificationActionTest extends TestCase
{
    /** @var \ArrayObject<int, Notification> */
    private \ArrayObject $recorded;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recorded = new \ArrayObject();
    }

    /** @return Notification[] */
    private function created(): array
    {
        return array_values($this->recorded->getArrayCopy());
    }

    public function testItWorksWithEveryTrigger(): void
    {
        // The whole point of this action: the operator wires up new kinds of
        // notification without anyone writing code for each event.
        self::assertSame([], $this->action()->getSupportedTriggerTypes());
    }

    public function testItRecordsAReservationNotification(): void
    {
        $reservation = $this->bookedReservation();

        $summary = $this->action()->execute(['severity' => 'warning'], $reservation, ['triggerType' => 'online_booking.created']);

        self::assertCount(1, $this->created());
        $notification = $this->created()[0];
        self::assertSame('reservation', $notification->getType());
        self::assertSame('notification.stored.reservation', $notification->getTitleKey());
        self::assertSame(NotificationSeverity::WARNING, $notification->getSeverity());
        self::assertSame('15.09.2026', $notification->getParams()['%from%']);
        self::assertSame(Reservation::class, $notification->getEntityClass());
        self::assertNotSame('', $summary);
    }

    public function testAnUnknownSeverityFallsBackToInfoInsteadOfFailing(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-15'));

        $this->action()->execute(['severity' => 'not-a-severity'], $reservation, ['triggerType' => 'online_booking.created']);

        self::assertSame(NotificationSeverity::INFO, $this->created()[0]->getSeverity());
    }

    public function testItDefaultsToTheReservationRoleWhenNoneIsConfigured(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-15'));

        $this->action()->execute(['requiredRole' => ''], $reservation, ['triggerType' => 'online_booking.created']);

        // An empty selection means "everyone who may see reservations at all",
        // not "literally every user" — booking data is not for all roles.
        self::assertSame('ROLE_RESERVATIONS_RO', $this->created()[0]->getRequiredRole());
    }

    public function testAConfiguredRoleWins(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-15'));

        $this->action()->execute(['requiredRole' => 'ROLE_ADMIN'], $reservation, ['triggerType' => 'online_booking.created']);

        self::assertSame('ROLE_ADMIN', $this->created()[0]->getRequiredRole());
    }

    public function testGroupBookingsAreCountedFromTheContext(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-15'));

        $this->action()->execute([], $reservation, ['triggerType' => 'online_booking.created', 'allReservations' => [$reservation, new Reservation()]]);

        self::assertSame(2, $this->created()[0]->getParams()['%count%']);
    }

    public function testAnUnsupportedEntityIsSkippedNotFatal(): void
    {
        $this->expectException(WorkflowSkippedException::class);

        $this->action()->execute([], new \stdClass(), []);
    }

    public function testTheOperatorsNoteIsCarriedThrough(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-15'));

        $this->action()->execute(['note' => 'Zahlungsziel überschritten'], $reservation, ['triggerType' => 'online_booking.created']);

        // With several automations feeding the bell, this is the only thing that
        // says which one produced the entry.
        self::assertSame('Zahlungsziel überschritten', $this->created()[0]->getNote());
    }

    public function testAnEmptyNoteIsStoredAsNullNotAsBlank(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-15'));

        $this->action()->execute(['note' => '   '], $reservation, ['triggerType' => 'online_booking.created']);

        self::assertNull($this->created()[0]->getNote(), 'A blank note must not render an empty line');
    }

    public function testAnImportedBookingNamesThePortalAndDropsTheGuest(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-15'));
        $reservation->setCalendarSyncImport((new CalendarSyncImport())->setName('Booking.com'));

        $this->action()->execute([], $reservation, ['triggerType' => 'calendar_import.created']);

        $notification = $this->created()[0];
        self::assertSame('calendar_import', $notification->getType());
        self::assertSame('notification.stored.calendar_import', $notification->getTitleKey());
        self::assertSame('Booking.com', $notification->getParams()['%source%']);
        // Portals never send guest details over iCal, so there is no name to show
        // and "unknown guest" would be noise.
        self::assertArrayNotHasKey('%name%', $notification->getParams());
    }

    public function testTheRoomNumberIsIncluded(): void
    {
        $reservation = $this->bookedReservation();
        $appartment = new Appartment();
        $appartment->setNumber('12');
        $reservation->setAppartment($appartment);

        $this->action()->execute([], $reservation, ['triggerType' => 'online_booking.created']);

        self::assertSame('12', $this->created()[0]->getParams()['%room%']);
    }

    public function testAReservationWithoutARoomDoesNotBreak(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-15'));

        $this->action()->execute([], $reservation, ['triggerType' => 'online_booking.created']);

        self::assertSame('–', $this->created()[0]->getParams()['%room%']);
    }

    public function testABookingCoveringSeveralRoomsUsesTheCountingTitle(): void
    {
        $reservation = $this->bookedReservation();
        $appartment = new Appartment();
        $appartment->setNumber('12');
        $reservation->setAppartment($appartment);

        $this->action()->execute([], $reservation, ['triggerType' => 'online_booking.created', 'allReservations' => [$reservation, new Reservation()]]);

        // Naming just the first room would be wrong when the booking covers more.
        self::assertSame('notification.stored.reservation_multi', $this->created()[0]->getTitleKey());
    }

    public function testAStatusChangeIsNotAnnouncedAsANewBooking(): void
    {
        $reservation = $this->bookedReservation();

        $this->action()->execute([], $reservation, ['triggerType' => 'reservation.status_changed']);

        // The action works with every trigger, so it must not claim a new booking
        // for an event that is nothing of the sort.
        self::assertSame('notification.stored.reservation_generic', $this->created()[0]->getTitleKey());
    }

    public function testAStatusChangeOnAnImportedBookingIsNeutralToo(): void
    {
        $reservation = $this->bookedReservation();
        $reservation->setCalendarSyncImport((new CalendarSyncImport())->setName('Booking.com'));

        $this->action()->execute([], $reservation, ['triggerType' => 'reservation.status_changed']);

        // The booking came from Booking.com once, but this event is not the
        // takeover — wording follows the trigger, not the entity.
        self::assertSame('notification.stored.reservation_generic', $this->created()[0]->getTitleKey());
    }

    public function testAnArrivalReminderIsNeutral(): void
    {
        $reservation = $this->bookedReservation();

        $this->action()->execute([], $reservation, ['triggerType' => 'reservation.days_before_start']);

        self::assertSame('notification.stored.reservation_generic', $this->created()[0]->getTitleKey());
    }

    public function testAManuallyCreatedReservationCountsAsNew(): void
    {
        $reservation = $this->bookedReservation();

        $this->action()->execute([], $reservation, ['triggerType' => 'reservation.created']);

        self::assertSame('notification.stored.reservation', $this->created()[0]->getTitleKey());
    }

    public function testAReservationWithoutABookerShowsNoNameAtAll(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-15'));

        $this->action()->execute([], $reservation, ['triggerType' => 'reservation.created']);

        $notification = $this->created()[0];
        // A placeholder like "unknown guest" is filler; the sentence has to drop
        // the name entirely, including the "from".
        self::assertSame('notification.stored.reservation_anonymous', $notification->getTitleKey());
        self::assertArrayNotHasKey('%name%', $notification->getParams());
    }

    public function testAStatusChangeWithoutABookerIsNamelessAndNeutral(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-15'));
        $reservation->setCalendarSyncImport((new CalendarSyncImport())->setName('Booking.com'));

        // The realistic case: an imported booking never has a booker, and a later
        // status change is neither new nor a takeover.
        $this->action()->execute([], $reservation, ['triggerType' => 'reservation.status_changed']);

        self::assertSame('notification.stored.reservation_generic_anonymous', $this->created()[0]->getTitleKey());
    }

    public function testSeveralRoomsWithoutABookerUseTheNamelessCountingTitle(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-15'));

        $this->action()->execute([], $reservation, [
            'triggerType' => 'reservation.created',
            'allReservations' => [$reservation, new Reservation()],
        ]);

        self::assertSame('notification.stored.reservation_multi_anonymous', $this->created()[0]->getTitleKey());
    }

    /** A reservation that has a booker, so the named titles are exercised. */
    private function bookedReservation(): Reservation
    {
        $booker = new Customer();
        $booker->setFirstname('Max');
        $booker->setLastname('Mustermann');

        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-15'));
        $reservation->setBooker($booker);

        return $reservation;
    }

    private function action(): CreateInAppNotificationAction
    {
        // A recording subclass rather than a mock: the assertions are about the
        // arguments the action chooses, and mocks add a PHPUnit notice per test.
        $recorded = $this->recorded;

        $service = new class(
            new NotificationProviderRegistry([]),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(NotificationRepository::class),
            $recorded,
        ) extends NotificationCenterService {
            /** @param \ArrayObject<int, Notification> $recorded */
            public function __construct(
                NotificationProviderRegistry $registry,
                EntityManagerInterface $em,
                NotificationRepository $repository,
                private readonly \ArrayObject $recorded,
            ) {
                parent::__construct($registry, $em, $repository);
            }

            public function create(
                string $type,
                string $titleKey,
                NotificationSeverity $severity = NotificationSeverity::INFO,
                array $params = [],
                ?string $routeName = null,
                array $routeParams = [],
                ?string $requiredRole = null,
                ?string $entityClass = null,
                ?string $entityId = null,
                ?string $note = null,
            ): Notification {
                $notification = (new Notification())
                    ->setType($type)
                    ->setTitleKey($titleKey)
                    ->setSeverity($severity)
                    ->setParams($params)
                    ->setRouteName($routeName)
                    ->setRequiredRole($requiredRole)
                    ->setEntityClass($entityClass)
                    ->setEntityId($entityId)
                    ->setNote('' === $note ? null : $note);

                $this->recorded->append($notification);

                return $notification;
            }
        };

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new CreateInAppNotificationAction($service, $translator);
    }
}
