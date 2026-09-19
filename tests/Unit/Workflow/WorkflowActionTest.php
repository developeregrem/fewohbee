<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Entity\Enum\InvoiceStatus;
use App\Entity\Enum\PaymentMeansCode;
use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Entity\Template;
use App\Entity\TemplateType;
use App\Repository\TemplateRepository;
use App\Service\DisplayNameResolver;
use App\Service\EInvoice\EInvoiceExportService;
use App\Service\EInvoice\EInvoiceReadinessService;
use App\Service\InvoiceService;
use App\Service\MailService;
use App\Service\TemplatesService;
use App\Service\ReservationService;
use App\Workflow\Action\ChangeInvoiceStatusAction;
use App\Workflow\Action\ChangePaymentMeansAction;
use App\Workflow\Action\ChangeReservationStatusAction;
use App\Workflow\Action\SendInvoiceEmailAction;
use App\Workflow\Attachment\WorkflowAttachmentResolver;
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
    private DisplayNameResolver $displayNameResolver;

    protected function setUp(): void
    {
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->translator = $this->createStub(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnArgument(0);
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->displayNameResolver = new DisplayNameResolver($this->translator);
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

        $action = new ChangeReservationStatusAction($em, $this->translator, $this->reservationService, $this->displayNameResolver);
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

        $action = new ChangeReservationStatusAction($em, $this->translator, $this->reservationService, $this->displayNameResolver);
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

        $action = new ChangeReservationStatusAction($em, $this->translator, $this->reservationService, $this->displayNameResolver);

        $this->expectException(WorkflowSkippedException::class);
        $action->execute(['statusId' => 5], new Invoice(), []);
    }

    public function testChangeReservationStatusSkipsWhenStatusNotFound(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        $action = new ChangeReservationStatusAction($em, $this->translator, $this->reservationService, $this->displayNameResolver);

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

        $action = new ChangeReservationStatusAction($em, $this->translator, $this->reservationService, $this->displayNameResolver);

        $this->expectException(WorkflowSkippedException::class);
        $action->execute(['statusId' => 5], new \stdClass(), []);
    }

    public function testChangeReservationStatusConfigSchemaUsesReservationStatusSelect(): void
    {
        $action = new ChangeReservationStatusAction($this->em, $this->translator, $this->reservationService, $this->displayNameResolver);
        $schema = $action->getConfigSchema();

        self::assertCount(1, $schema);
        self::assertSame('statusId', $schema[0]['key']);
        self::assertSame('reservation_status_select', $schema[0]['type']);
    }

    // -------------------------------------------------------------------------
    // SendInvoiceEmailAction – invoice PDF template selection
    // -------------------------------------------------------------------------

    public function testInvoiceEmailUsesTheConfiguredPdfTemplate(): void
    {
        $chosen = self::template('Rechnung kompakt', 'TEMPLATE_INVOICE_PDF');
        $action = $this->createInvoiceEmailAction(
            findResult: $chosen,
            defaultTemplate: self::template('Standardrechnung', 'TEMPLATE_INVOICE_PDF'),
        );

        self::assertSame($chosen, $this->resolvePdfTemplate($action, ['invoicePdfTemplateId' => 42]));
    }

    public function testInvoiceEmailFallsBackToTheDefaultPdfTemplate(): void
    {
        $default = self::template('Standardrechnung', 'TEMPLATE_INVOICE_PDF');
        $action = $this->createInvoiceEmailAction(
            findResult: self::template('Rechnung kompakt', 'TEMPLATE_INVOICE_PDF'),
            defaultTemplate: $default,
        );

        // Neither an explicit choice nor a leftover 0 from the "–" option picks a template.
        self::assertSame($default, $this->resolvePdfTemplate($action, []));
        self::assertSame($default, $this->resolvePdfTemplate($action, ['invoicePdfTemplateId' => 0]));
    }

    public function testInvoiceEmailSkipsWhenTheConfiguredPdfTemplateHasTheWrongType(): void
    {
        $action = $this->createInvoiceEmailAction(
            findResult: self::template('Rechnungsmail', 'TEMPLATE_INVOICE_EMAIL'),
            defaultTemplate: self::template('Standardrechnung', 'TEMPLATE_INVOICE_PDF'),
        );

        $this->expectException(WorkflowSkippedException::class);
        $this->resolvePdfTemplate($action, ['invoicePdfTemplateId' => 42]);
    }

    public function testInvoiceEmailSkipsWhenTheConfiguredPdfTemplateIsGone(): void
    {
        $action = $this->createInvoiceEmailAction(
            findResult: null,
            defaultTemplate: self::template('Standardrechnung', 'TEMPLATE_INVOICE_PDF'),
        );

        $this->expectException(WorkflowSkippedException::class);
        $this->resolvePdfTemplate($action, ['invoicePdfTemplateId' => 42]);
    }

    public function testInvoiceEmailConfigSchemaOffersInvoicePdfTemplates(): void
    {
        $action = $this->createInvoiceEmailAction(null, null);

        $field = null;
        foreach ($action->getConfigSchema() as $candidate) {
            if ('invoicePdfTemplateId' === ($candidate['key'] ?? '')) {
                $field = $candidate;
                break;
            }
        }

        self::assertNotNull($field);
        self::assertSame('template_select', $field['type']);
        self::assertSame(['TEMPLATE_INVOICE_PDF'], $field['templateTypes']);
    }

    private static function template(string $name, string $typeName): Template
    {
        $type = new TemplateType();
        $type->setName($typeName);

        $template = new Template();
        $template->setName($name);
        $template->setTemplateType($type);

        return $template;
    }

    /** @param array<string, mixed> $config */
    private function resolvePdfTemplate(SendInvoiceEmailAction $action, array $config): Template
    {
        // The resolution is deliberately private; going through execute() would pull
        // in PDF rendering, mailing and persistence for a pure lookup decision.
        return (new \ReflectionMethod($action, 'resolvePdfTemplate'))->invoke($action, $config);
    }

    private function createInvoiceEmailAction(?Template $findResult, ?Template $defaultTemplate): SendInvoiceEmailAction
    {
        $repository = $this->createStub(TemplateRepository::class);
        $repository->method('find')->willReturn($findResult);
        $repository->method('loadByTypeName')->willReturn(null !== $defaultTemplate ? [$defaultTemplate] : []);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $templatesService = $this->createStub(TemplatesService::class);
        $templatesService->method('getDefaultTemplate')->willReturn($defaultTemplate);

        return new SendInvoiceEmailAction(
            $templatesService,
            $this->createStub(MailService::class),
            $this->createStub(InvoiceService::class),
            $this->createStub(EInvoiceReadinessService::class),
            $this->createStub(EInvoiceExportService::class),
            $this->createStub(WorkflowAttachmentResolver::class),
            $em,
            $this->translator,
        );
    }
}
