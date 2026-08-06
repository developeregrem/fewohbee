<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Entity\Enum\InvoiceStatus;
use App\Entity\Invoice;
use App\Entity\MailAttachment;
use App\Entity\InvoiceSettingsData;
use App\Entity\Reservation;
use App\Entity\Template;
use App\Entity\TemplateType;
use App\Repository\TemplateRepository;
use App\Service\EInvoice\EInvoiceExportService;
use App\Service\EInvoice\EInvoiceReadiness;
use App\Service\EInvoice\EInvoiceReadinessService;
use App\Service\EInvoice\Validation\EInvoiceFixLocation;
use App\Service\EInvoice\Validation\EInvoiceValidationResult;
use App\Service\EInvoice\Validation\EInvoiceViolation;
use App\Service\InvoiceService;
use App\Service\TemplatesService;
use App\Workflow\Attachment\ResolvedAttachment;
use App\Workflow\Attachment\WorkflowAttachmentResolver;
use App\Workflow\WorkflowSkippedException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WorkflowAttachmentResolverTest extends TestCase
{
    private TemplatesService $templatesService;
    private InvoiceService $invoiceService;
    private EInvoiceReadinessService $readinessService;
    private EInvoiceExportService $exportService;
    private EntityManagerInterface $em;
    private TranslatorInterface $translator;
    private TemplateRepository $repository;
    private InvoiceSettingsData $settings;

    /** @var array<int, Template> templates the repository stub can find */
    private array $templates = [];
    /** @var Template[] result of loadByTypeName (the invoice PDF templates) */
    private array $invoicePdfTemplates = [];

    protected function setUp(): void
    {
        $this->templatesService = $this->createStub(TemplatesService::class);
        $this->invoiceService = $this->createStub(InvoiceService::class);
        $this->readinessService = $this->createStub(EInvoiceReadinessService::class);
        $this->exportService = $this->createStub(EInvoiceExportService::class);
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->translator = $this->createStub(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnCallback(
            static fn (string $key, array $params = []): string => $key
        );

        $this->settings = new InvoiceSettingsData();

        $this->repository = $this->createStub(TemplateRepository::class);
        $this->repository->method('find')->willReturnCallback(fn (int $id): ?Template => $this->templates[$id] ?? null);
        $this->repository->method('loadByTypeName')->willReturnCallback(fn (): array => $this->invoicePdfTemplates);
        $this->em->method('getRepository')->willReturn($this->repository);

        $this->templatesService->method('renderTemplate')->willReturn('<html>rendered</html>');
        $this->templatesService->method('getPDFOutput')->willReturn('PDF_BYTES');
        $this->templatesService->method('getDefaultTemplate')->willReturnCallback(
            static fn (array $templates): ?Template => $templates[0] ?? null
        );

        $this->invoiceService->method('sanitizeFilename')->willReturnCallback(
            static fn (string $value): string => str_replace(' ', '_', $value)
        );
        $this->invoiceService->method('buildInvoiceExportFilename')->willReturnCallback(
            static fn (Invoice $invoice, bool $einvoice = false): string => 'R-'.$invoice->getNumber().($einvoice ? '_einvoice' : '')
        );

        $this->readinessService->method('getActiveSettings')->willReturn($this->settings);
    }

    private function buildResolver(): WorkflowAttachmentResolver
    {
        return new WorkflowAttachmentResolver(
            $this->templatesService,
            $this->invoiceService,
            $this->readinessService,
            $this->exportService,
            $this->em,
            $this->translator,
        );
    }

    private function registerTemplate(string $name, string $typeName, int $id): Template
    {
        $type = new TemplateType();
        $type->setName($typeName);
        $template = new Template();
        $template->setName($name);
        $template->setTemplateType($type);

        $idProperty = new \ReflectionProperty(Template::class, 'id');
        $idProperty->setValue($template, $id);

        $this->templates[$id] = $template;

        return $template;
    }

    private function buildInvoice(string $number, int $status = 1, ?int $id = null): Invoice
    {
        $invoice = new Invoice();
        $invoice->setNumber($number);
        $invoice->setStatus($status);

        if (null !== $id) {
            $idProperty = new \ReflectionProperty(Invoice::class, 'id');
            $idProperty->setValue($invoice, $id);
        }

        return $invoice;
    }

    private function readiness(bool $ready): EInvoiceReadiness
    {
        $result = $ready
            ? new EInvoiceValidationResult([])
            : new EInvoiceValidationResult([new EInvoiceViolation('buyerCountry', 'invoice.einvoice.violation.buyerCountry', EInvoiceFixLocation::INVOICE)]);

        return new EInvoiceReadiness(true, $ready, 'en16931', $result);
    }

    // --- PDF templates ---

    public function testStaticPdfTemplateIsRenderedWithoutEntity(): void
    {
        $this->registerTemplate('AGB', 'TEMPLATE_FILE_PDF', 12);

        $this->templatesService = $this->createMock(TemplatesService::class);
        $this->templatesService->expects(self::once())
            ->method('renderTemplate')
            ->with(12, null)
            ->willReturn('<html>agb</html>');
        $this->templatesService->method('getPDFOutput')->willReturn('PDF_BYTES');

        $set = $this->buildResolver()->resolve([['type' => 'pdf_template', 'templateId' => 12]], null, []);

        self::assertSame(1, $set->count());
        self::assertSame([], $set->warnings);
        $mail = $set->mailAttachments()[0];
        self::assertSame('AGB.pdf', $mail->getName());
        self::assertSame('application/pdf', $mail->getContentType());
        self::assertSame('PDF_BYTES', $mail->getBody());
        self::assertSame('<html>agb</html>', $set->attachments[0]->renderedHtml);
        self::assertNull($set->attachments[0]->binaryPayload);
    }

    public function testReservationPdfTemplateReceivesTheWholeReservationGroup(): void
    {
        $this->registerTemplate('Buchungsbestaetigung', 'TEMPLATE_RESERVATION_PDF', 20);
        $reservations = [new Reservation(), new Reservation()];

        $this->templatesService = $this->createMock(TemplatesService::class);
        $this->templatesService->expects(self::once())
            ->method('renderTemplate')
            ->with(20, $reservations)
            ->willReturn('<html>booking</html>');
        $this->templatesService->method('getPDFOutput')->willReturn('PDF_BYTES');

        $set = $this->buildResolver()->resolve([['type' => 'pdf_template', 'templateId' => 20]], $reservations[0], $reservations);

        self::assertSame(1, $set->count());
    }

    public function testReservationPdfTemplateWithoutReservationsWarnsInsteadOfFailing(): void
    {
        $this->registerTemplate('Buchungsbestaetigung', 'TEMPLATE_RESERVATION_PDF', 20);

        $set = $this->buildResolver()->resolve([['type' => 'pdf_template', 'templateId' => 20]], null, []);

        self::assertSame(0, $set->count());
        self::assertSame(['workflow.log.attachment_no_reservation'], $set->warnings);
    }

    public function testUnknownTemplateIsReportedAsWarning(): void
    {
        $set = $this->buildResolver()->resolve([['type' => 'pdf_template', 'templateId' => 999]], null, []);

        self::assertSame(0, $set->count());
        self::assertSame(['workflow.log.attachment_template_not_found'], $set->warnings);
    }

    public function testEmailTemplatePickedAsAttachmentIsRejected(): void
    {
        $this->registerTemplate('Willkommen', 'TEMPLATE_RESERVATION_EMAIL', 30);

        $set = $this->buildResolver()->resolve([['type' => 'pdf_template', 'templateId' => 30]], null, []);

        self::assertSame(0, $set->count());
        self::assertSame(['workflow.log.attachment_template_incompatible'], $set->warnings);
    }

    public function testRenderFailureDoesNotStopTheOtherAttachments(): void
    {
        $this->registerTemplate('Kaputt', 'TEMPLATE_FILE_PDF', 40);
        $this->registerTemplate('Hausordnung', 'TEMPLATE_FILE_PDF', 41);

        $this->templatesService = $this->createStub(TemplatesService::class);
        $this->templatesService->method('renderTemplate')->willReturnCallback(
            static function (int $id): string {
                if (40 === $id) {
                    throw new \RuntimeException('boom');
                }

                return '<html>ok</html>';
            }
        );
        $this->templatesService->method('getPDFOutput')->willReturn('PDF_BYTES');

        $set = $this->buildResolver()->resolve([
            ['type' => 'pdf_template', 'templateId' => 40],
            ['type' => 'pdf_template', 'templateId' => 41],
        ], null, []);

        self::assertSame(1, $set->count());
        self::assertSame('Hausordnung.pdf', $set->mailAttachments()[0]->getName());
        self::assertSame(['workflow.log.attachment_render_failed'], $set->warnings);
    }

    public function testRequireAllPolicyTurnsWarningsIntoSkip(): void
    {
        $this->expectException(WorkflowSkippedException::class);
        $this->expectExceptionMessageMatches('/skipped_attachment_missing/');

        $this->buildResolver()->resolve(
            [['type' => 'pdf_template', 'templateId' => 999]],
            null,
            [],
            WorkflowAttachmentResolver::POLICY_REQUIRE_ALL
        );
    }

    public function testDuplicateFilenamesAreMadeUnique(): void
    {
        $this->registerTemplate('Infoblatt', 'TEMPLATE_FILE_PDF', 50);
        $this->registerTemplate('Infoblatt', 'TEMPLATE_FILE_PDF', 51);

        $set = $this->buildResolver()->resolve([
            ['type' => 'pdf_template', 'templateId' => 50],
            ['type' => 'pdf_template', 'templateId' => 51],
        ], null, []);

        self::assertSame(['Infoblatt.pdf', 'Infoblatt_2.pdf'], array_map(
            static fn ($a): string => $a->getName(),
            $set->mailAttachments()
        ));
    }

    public function testAttachmentsExceedingTheSizeLimitSkipTheWorkflow(): void
    {
        $this->registerTemplate('Riesig', 'TEMPLATE_FILE_PDF', 60);

        $this->templatesService = $this->createStub(TemplatesService::class);
        $this->templatesService->method('renderTemplate')->willReturn('<html></html>');
        $this->templatesService->method('getPDFOutput')
            ->willReturn(str_repeat('x', WorkflowAttachmentResolver::MAX_TOTAL_BYTES + 1));

        $this->expectException(WorkflowSkippedException::class);
        $this->expectExceptionMessageMatches('/skipped_attachments_too_large/');

        $this->buildResolver()->resolve([['type' => 'pdf_template', 'templateId' => 60]], null, []);
    }

    public function testMalformedAndDuplicateItemsAreIgnored(): void
    {
        $this->registerTemplate('AGB', 'TEMPLATE_FILE_PDF', 70);

        $set = $this->buildResolver()->resolve([
            'not-an-array',
            ['type' => 'nope'],
            ['type' => 'pdf_template'],
            ['type' => 'pdf_template', 'templateId' => 70],
            ['type' => 'pdf_template', 'templateId' => 70],
        ], null, []);

        self::assertSame(1, $set->count());
        self::assertSame([], $set->warnings);
    }

    public function testEmptyConfigProducesNothing(): void
    {
        $set = $this->buildResolver()->resolve([], null, []);

        self::assertSame(0, $set->count());
        self::assertSame([], $set->warnings);
    }

    // --- Invoices ---

    public function testReadyInvoiceIsAttachedAsHybridPdf(): void
    {
        $this->invoicePdfTemplates = [$this->registerTemplate('Rechnung PDF', 'TEMPLATE_INVOICE_PDF', 7)];
        $this->readinessService->method('check')->willReturn($this->readiness(true));
        $this->invoiceService->method('generateInvoicePdfXml')->willReturn('HYBRID_BYTES');

        $invoice = $this->buildInvoice('1001');
        $set = $this->buildResolver()->resolve([['type' => 'invoice_pdf']], $invoice, []);

        self::assertSame(1, $set->count());
        self::assertSame('R-1001_einvoice.pdf', $set->mailAttachments()[0]->getName());
        self::assertSame('HYBRID_BYTES', $set->mailAttachments()[0]->getBody());
        self::assertSame('HYBRID_BYTES', $set->attachments[0]->binaryPayload);
    }

    public function testEInvoiceGenerationFailureFallsBackToPlainPdf(): void
    {
        $this->invoicePdfTemplates = [$this->registerTemplate('Rechnung PDF', 'TEMPLATE_INVOICE_PDF', 7)];
        $this->readinessService->method('check')->willReturn($this->readiness(true));
        $this->invoiceService->method('generateInvoicePdfXml')->willThrowException(new \RuntimeException('merge failed'));

        $set = $this->buildResolver()->resolve([['type' => 'invoice_pdf']], $this->buildInvoice('1001'), []);

        self::assertSame(1, $set->count());
        self::assertSame('R-1001.pdf', $set->mailAttachments()[0]->getName());
        self::assertSame('PDF_BYTES', $set->mailAttachments()[0]->getBody());
        // Plain PDFs are re-rendered on download instead of being stored.
        self::assertNull($set->attachments[0]->binaryPayload);
        self::assertSame([], $set->warnings);
    }

    public function testSharedAndCancelledInvoicesOfAGroupBookingAreFiltered(): void
    {
        $this->invoicePdfTemplates = [$this->registerTemplate('Rechnung PDF', 'TEMPLATE_INVOICE_PDF', 7)];
        $this->readinessService->method('check')->willReturn($this->readiness(false));

        // One invoice shared by both reservations (ManyToMany) plus a cancelled one.
        $shared = $this->buildInvoice('1001', InvoiceStatus::OPEN->value, 100);
        $cancelled = $this->buildInvoice('1002', InvoiceStatus::CANCELED->value, 101);

        $first = new Reservation();
        $first->addInvoice($shared);
        $first->addInvoice($cancelled);
        $second = new Reservation();
        $second->addInvoice($shared);

        $set = $this->buildResolver()->resolve([['type' => 'invoice_pdf']], $first, [$first, $second]);

        self::assertSame(1, $set->count());
        self::assertSame('R-1001.pdf', $set->mailAttachments()[0]->getName());
    }

    public function testOnlyOpenInvoicesModeSkipsPaidInvoices(): void
    {
        $this->invoicePdfTemplates = [$this->registerTemplate('Rechnung PDF', 'TEMPLATE_INVOICE_PDF', 7)];

        $reservation = new Reservation();
        $reservation->addInvoice($this->buildInvoice('1001', InvoiceStatus::PAID->value, 100));

        $set = $this->buildResolver()->resolve([['type' => 'invoice_pdf_open']], $reservation, [$reservation]);

        self::assertSame(0, $set->count());
        self::assertSame(['workflow.log.attachment_no_open_invoice'], $set->warnings);
    }

    public function testMissingInvoiceIsReportedAsWarning(): void
    {
        $this->invoicePdfTemplates = [$this->registerTemplate('Rechnung PDF', 'TEMPLATE_INVOICE_PDF', 7)];

        $reservation = new Reservation();
        $set = $this->buildResolver()->resolve([['type' => 'invoice_pdf']], $reservation, [$reservation]);

        self::assertSame(0, $set->count());
        self::assertSame(['workflow.log.attachment_no_invoice'], $set->warnings);
    }

    public function testMissingInvoicePdfTemplateIsReportedAsWarning(): void
    {
        $this->invoicePdfTemplates = [];

        $set = $this->buildResolver()->resolve([['type' => 'invoice_pdf']], $this->buildInvoice('1001'), []);

        self::assertSame(0, $set->count());
        self::assertSame(['workflow.log.skipped_no_pdf_template'], $set->warnings);
    }

    // --- Correspondence ---

    public function testCreateFileCorrespondenceTruncatesLongNames(): void
    {
        $template = $this->registerTemplate('AGB', 'TEMPLATE_FILE_PDF', 80);
        $attachment = new ResolvedAttachment(
            new MailAttachment('PDF_BYTES', 'agb.pdf', 'application/pdf'),
            str_repeat('a', 150),
            $template,
            '<html>agb</html>',
        );

        $reservation = new Reservation();
        $file = $this->buildResolver()->createFileCorrespondence($attachment, $reservation);

        self::assertNotNull($file);
        self::assertSame(100, mb_strlen($file->getName()));
        self::assertSame(100, mb_strlen($file->getFileName()));
        self::assertSame('<html>agb</html>', $file->getText());
        self::assertSame($template, $file->getTemplate());
        self::assertSame($reservation, $file->getReservation());
        self::assertNull($file->getBinaryPayload());
    }

    public function testCreateFileCorrespondenceReturnsNullWithoutTemplate(): void
    {
        $attachment = new ResolvedAttachment(
            new MailAttachment('BYTES', 'file.pdf', 'application/pdf'),
            'file',
            null,
            '',
        );

        self::assertNull($this->buildResolver()->createFileCorrespondence($attachment, new Reservation()));
    }

    public function testCreateFileCorrespondenceStoresBytesForHybridInvoices(): void
    {
        $pdfTemplate = $this->registerTemplate('Rechnung PDF', 'TEMPLATE_INVOICE_PDF', 7);
        $this->invoicePdfTemplates = [$pdfTemplate];
        $this->readinessService->method('check')->willReturn($this->readiness(true));
        $this->invoiceService->method('generateInvoicePdfXml')->willReturn('HYBRID_BYTES');

        $resolver = $this->buildResolver();
        $set = $resolver->resolve([['type' => 'invoice_pdf']], $this->buildInvoice('1001'), []);

        $file = $resolver->createFileCorrespondence($set->attachments[0], new Reservation());

        self::assertNotNull($file);
        self::assertSame('R-1001_einvoice', $file->getFileName());
        self::assertSame('HYBRID_BYTES', $file->getBinaryPayload());
    }
}
