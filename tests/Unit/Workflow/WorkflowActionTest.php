<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Entity\Enum\InvoiceStatus;
use App\Entity\Enum\PaymentMeansCode;
use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Service\ReservationService;
use App\Workflow\Action\ChangeInvoiceStatusAction;
use App\Workflow\Action\ChangePaymentMeansAction;
use App\Workflow\Action\ChangeReservationStatusAction;
use App\Workflow\WorkflowSkippedException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WorkflowActionTest extends TestCase
{
    private EntityManagerInterface $em;
    private TranslatorInterface $translator;
    private EventDispatcherInterface $eventDispatcher;
    private ReservationService $reservationService;

    protected function setUp(): void
    {
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->translator = $this->createStub(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnArgument(0);
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->reservationService = $this->createStub(ReservationService::class);
        $this->reservationService->method('changeStatus')->willReturnCallback(
            static function (Reservation $reservation, ?ReservationStatus $status): void {
                $reservation->setReservationStatus($status);
            }
        );
    }

    // -------------------------------------------------------------------------
    // ChangeInvoiceStatusAction
    // -------------------------------------------------------------------------

    public function testChangeInvoiceStatusSetsStatus(): void
    {
        $action = new ChangeInvoiceStatusAction($this->em, $this->translator, $this->eventDispatcher);

        $invoice = new Invoice();
        $invoice->setNumber('R-2026-001');

        $result = $action->execute(['status' => 2], $invoice, []);

        self::assertSame(2, $invoice->getStatus());
        self::assertIsString($result);
    }

    public function testChangeInvoiceStatusSkipsForInvalidStatus(): void
    {
        $action = new ChangeInvoiceStatusAction($this->em, $this->translator, $this->eventDispatcher);

        $this->expectException(WorkflowSkippedException::class);
        $action->execute(['status' => 999], new Invoice(), []);
    }

    public function testChangeInvoiceStatusSkipsForMissingConfig(): void
    {
        $action = new ChangeInvoiceStatusAction($this->em, $this->translator, $this->eventDispatcher);

        $this->expectException(WorkflowSkippedException::class);
        $action->execute([], new Invoice(), []);
    }

    public function testChangeInvoiceStatusSkipsForWrongEntity(): void
    {
        $action = new ChangeInvoiceStatusAction($this->em, $this->translator, $this->eventDispatcher);

        $this->expectException(WorkflowSkippedException::class);
        $action->execute(['status' => 2], new Reservation(), []);
    }

    public function testChangeInvoiceStatusConfigSchemaHasAllStatuses(): void
    {
        $action = new ChangeInvoiceStatusAction($this->em, $this->translator, $this->eventDispatcher);
        $schema = $action->getConfigSchema();

        self::assertCount(1, $schema);
        self::assertSame('status', $schema[0]['key']);
        self::assertSame('select', $schema[0]['type']);
        self::assertCount(count(InvoiceStatus::cases()), $schema[0]['options']);
    }

    // -------------------------------------------------------------------------
    // ChangePaymentMeansAction
    // -------------------------------------------------------------------------

    public function testChangePaymentMeansSetsCode(): void
    {
        $action = new ChangePaymentMeansAction($this->em, $this->translator);

        $invoice = new Invoice();
        $invoice->setNumber('R-2026-001');

        $result = $action->execute(['paymentMeansCode' => 10], $invoice, []);

        self::assertSame(PaymentMeansCode::CASH, $invoice->getPaymentMeans());
        self::assertIsString($result);
    }

    public function testChangePaymentMeansSkipsForInvalidCode(): void
    {
        $action = new ChangePaymentMeansAction($this->em, $this->translator);

        $this->expectException(WorkflowSkippedException::class);
        $action->execute(['paymentMeansCode' => 999], new Invoice(), []);
    }

    public function testChangePaymentMeansSkipsForMissingConfig(): void
    {
        $action = new ChangePaymentMeansAction($this->em, $this->translator);

        $this->expectException(WorkflowSkippedException::class);
        $action->execute([], new Invoice(), []);
    }

    public function testChangePaymentMeansSkipsForWrongEntity(): void
    {
        $action = new ChangePaymentMeansAction($this->em, $this->translator);

        $this->expectException(WorkflowSkippedException::class);
        $action->execute(['paymentMeansCode' => 10], new Reservation(), []);
    }

    public function testChangePaymentMeansConfigSchemaHasAllCodes(): void
    {
        $action = new ChangePaymentMeansAction($this->em, $this->translator);
        $schema = $action->getConfigSchema();

        self::assertCount(1, $schema);
        self::assertSame('paymentMeansCode', $schema[0]['key']);
        self::assertSame('select', $schema[0]['type']);
        self::assertCount(count(PaymentMeansCode::cases()), $schema[0]['options']);
    }

    // -------------------------------------------------------------------------
    // ChangeReservationStatusAction – single Reservation
    // -------------------------------------------------------------------------

    public function testChangeReservationStatusOnReservation(): void
    {
        $status = new ReservationStatus();
        $status->setName('Bezahlt');
        $status->setColor('#00ff00');

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($status);

        $reservation = new Reservation();

        $action = new ChangeReservationStatusAction($em, $this->translator, $this->reservationService);
        $result = $action->execute(['statusId' => 5], $reservation, []);

        self::assertSame($status, $reservation->getReservationStatus());
        self::assertIsString($result);
    }

    // -------------------------------------------------------------------------
    // ChangeReservationStatusAction – Invoice with linked Reservations
    // -------------------------------------------------------------------------

    public function testChangeReservationStatusOnInvoiceChangesAllLinkedReservations(): void
    {
        $status = new ReservationStatus();
        $status->setName('Bezahlt');
        $status->setColor('#00ff00');

        $res1 = new Reservation();
        $res2 = new Reservation();

        $invoice = new Invoice();
        $invoice->addReservation($res1);
        $invoice->addReservation($res2);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($status);

        $action = new ChangeReservationStatusAction($em, $this->translator, $this->reservationService);
        $result = $action->execute(['statusId' => 5], $invoice, []);

        self::assertSame($status, $res1->getReservationStatus());
        self::assertSame($status, $res2->getReservationStatus());
        self::assertIsString($result);
    }

    public function testChangeReservationStatusOnInvoiceSkipsWhenNoReservations(): void
    {
        $status = new ReservationStatus();
        $status->setName('Bezahlt');
        $status->setColor('#00ff00');

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($status);

        $action = new ChangeReservationStatusAction($em, $this->translator, $this->reservationService);

        $this->expectException(WorkflowSkippedException::class);
        $action->execute(['statusId' => 5], new Invoice(), []);
    }

    public function testChangeReservationStatusSkipsWhenStatusNotFound(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        $action = new ChangeReservationStatusAction($em, $this->translator, $this->reservationService);

        $this->expectException(WorkflowSkippedException::class);
        $action->execute(['statusId' => 999], new Reservation(), []);
    }

    public function testChangeReservationStatusSkipsForUnsupportedEntity(): void
    {
        $status = new ReservationStatus();
        $status->setName('Test');
        $status->setColor('#000000');

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($status);

        $action = new ChangeReservationStatusAction($em, $this->translator, $this->reservationService);

        $this->expectException(WorkflowSkippedException::class);
        $action->execute(['statusId' => 5], new \stdClass(), []);
    }

    public function testChangeReservationStatusConfigSchemaUsesReservationStatusSelect(): void
    {
        $action = new ChangeReservationStatusAction($this->em, $this->translator, $this->reservationService);
        $schema = $action->getConfigSchema();

        self::assertCount(1, $schema);
        self::assertSame('statusId', $schema[0]['key']);
        self::assertSame('reservation_status_select', $schema[0]['type']);
    }
}
