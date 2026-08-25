<?php

declare(strict_types=1);

namespace App\Tests\Unit;

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
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-15'));

        $summary = $this->action()->execute(['severity' => 'warning'], $reservation, []);

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

        $this->action()->execute(['severity' => 'not-a-severity'], $reservation, []);

        self::assertSame(NotificationSeverity::INFO, $this->created()[0]->getSeverity());
    }

    public function testItDefaultsToTheReservationRoleWhenNoneIsConfigured(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-15'));

        $this->action()->execute(['requiredRole' => ''], $reservation, []);

        // An empty selection means "everyone who may see reservations at all",
        // not "literally every user" — booking data is not for all roles.
        self::assertSame('ROLE_RESERVATIONS_RO', $this->created()[0]->getRequiredRole());
    }

    public function testAConfiguredRoleWins(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-15'));

        $this->action()->execute(['requiredRole' => 'ROLE_ADMIN'], $reservation, []);

        self::assertSame('ROLE_ADMIN', $this->created()[0]->getRequiredRole());
    }

    public function testGroupBookingsAreCountedFromTheContext(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-15'));

        $this->action()->execute([], $reservation, ['allReservations' => [$reservation, new Reservation()]]);

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

        $this->action()->execute(['note' => 'Zahlungsziel überschritten'], $reservation, []);

        // With several automations feeding the bell, this is the only thing that
        // says which one produced the entry.
        self::assertSame('Zahlungsziel überschritten', $this->created()[0]->getNote());
    }

    public function testAnEmptyNoteIsStoredAsNullNotAsBlank(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-09-15'));

        $this->action()->execute(['note' => '   '], $reservation, []);

        self::assertNull($this->created()[0]->getNote(), 'A blank note must not render an empty line');
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
