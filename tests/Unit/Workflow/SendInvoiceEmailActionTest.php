<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Entity\FileCorrespondence;
use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use App\Entity\MailCorrespondence;
use App\Entity\Reservation;
use App\Entity\Template;
use App\Entity\TemplateType;
use App\Service\EInvoice\EInvoiceExportService;
use App\Service\EInvoice\EInvoiceReadiness;
use App\Service\EInvoice\EInvoiceReadinessService;
use App\Service\EInvoice\Validation\EInvoiceFixLocation;
use App\Service\EInvoice\Validation\EInvoiceValidationResult;
use App\Service\EInvoice\Validation\EInvoiceViolation;
use App\Service\InvoiceService;
use App\Repository\TemplateRepository;
use App\Service\MailService;
use App\Service\TemplatesService;
use App\Workflow\Action\SendInvoiceEmailAction;
use App\Workflow\WorkflowSkippedException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SendInvoiceEmailActionTest extends TestCase
{
    private TemplatesService $templatesService;
    private MailService $mailService;
    private InvoiceService $invoiceService;
    private EInvoiceReadinessService $readinessService;
    private EInvoiceExportService $exportService;
    private EntityManagerInterface $em;
    private TranslatorInterface $translator;
    private InvoiceSettingsData $settings;
    private Template $emailTemplate;
    private Template $pdfTemplate;
    private TemplateRepository $repository;

    // Replaces the default stubs with mocks for tests that verify expectations.
    private function useMailServiceMock(): void
    {
        $this->mailService = $this->createMock(MailService::class);
    }

    private function useEntityManagerMock(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getRepository')->willReturn($this->repository);
    }

    protected function setUp(): void
    {
        $this->templatesService = $this->createStub(TemplatesService::class);
        $this->mailService = $this->createStub(MailService::class);
        $this->invoiceService = $this->createStub(InvoiceService::class);
        $this->readinessService = $this->createStub(EInvoiceReadinessService::class);
        $this->exportService = $this->createStub(EInvoiceExportService::class);
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->translator = $this->createStub(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnCallback(
            static fn (string $key, array $params = []): string => $key.(isset($params['%fields%']) ? ' '.$params['%fields%'] : '')
        );

        $this->settings = new InvoiceSettingsData();

        $this->emailTemplate = $this->buildTemplate('Rechnung per Mail', 'TEMPLATE_INVOICE_EMAIL', 5);
        $this->pdfTemplate = $this->buildTemplate('Rechnung PDF', 'TEMPLATE_INVOICE_PDF', 7);

        $this->repository = $this->createStub(TemplateRepository::class);
        $this->repository->method('find')->willReturn($this->emailTemplate);
        $this->repository->method('loadByTypeName')->willReturn([$this->pdfTemplate]);
        $this->em->method('getRepository')->willReturn($this->repository);

        $this->templatesService->method('getDefaultTemplate')->willReturn($this->pdfTemplate);
        $this->templatesService->method('renderTemplate')->willReturn('<html>rendered</html>');
        $this->templatesService->method('getPDFOutput')->willReturn('PLAIN_PDF_BYTES');

        $this->invoiceService->method('buildInvoiceExportFilename')->willReturnCallback(
            static fn (Invoice $invoice, bool $einvoice = false): string => 'R-1'.($einvoice ? '_einvoice' : '')
        );

        $this->readinessService->method('getActiveSettings')->willReturn($this->settings);
    }

    private function buildTemplate(string $name, string $typeName, int $id = 1): Template
    {
        $type = new TemplateType();
        $type->setName($typeName);
        $template = new Template();
        $template->setName($name);
        $template->setTemplateType($type);

        $idProperty = new \ReflectionProperty(Template::class, 'id');
        $idProperty->setValue($template, $id);

        return $template;
    }

    private function buildAction(): SendInvoiceEmailAction
    {
        return new SendInvoiceEmailAction(
            $this->templatesService,
            $this->mailService,
            $this->invoiceService,
            $this->readinessService,
            $this->exportService,
            $this->em,
            $this->translator,
        );
    }

    private function buildInvoice(): Invoice
    {
        $invoice = new Invoice();
        $invoice->setNumber('R-1');
        $invoice->setEmail('guest@example.com');

        return $invoice;
    }

    private function readiness(bool $ready): EInvoiceReadiness
    {
        $result = $ready
            ? new EInvoiceValidationResult([])
            : new EInvoiceValidationResult([new EInvoiceViolation('buyerCountry', 'invoice.einvoice.violation.buyerCountry', EInvoiceFixLocation::INVOICE)]);

        return new EInvoiceReadiness(true, $ready, 'en16931', $result);
    }

    private function baseConfig(array $overrides = []): array
    {
        return array_merge([
            'templateId' => 5,
            'recipientType' => 'invoice_email',
            'attachmentMode' => 'einvoice_preferred',
            'attachXml' => 'no',
        ], $overrides);
    }

    public function testSkipsForNonInvoiceEntity(): void
    {
        $this->expectException(WorkflowSkippedException::class);
        $this->buildAction()->execute($this->baseConfig(), new Reservation(), []);
    }

    public function testSkipsWithoutTemplate(): void
    {
        $this->expectException(WorkflowSkippedException::class);
        $this->buildAction()->execute($this->baseConfig(['templateId' => 0]), $this->buildInvoice(), []);
    }

    public function testSkipsWithoutRecipient(): void
    {
        $invoice = $this->buildInvoice();
        $invoice->setEmail(null);

        $this->expectException(WorkflowSkippedException::class);
        $this->buildAction()->execute($this->baseConfig(), $invoice, []);
    }

    public function testRequiredModeSkipsWhenNotReady(): void
    {
        $this->readinessService->method('check')->willReturn($this->readiness(false));

        $this->expectException(WorkflowSkippedException::class);
        $this->expectExceptionMessageMatches('/skipped_einvoice_invalid/');
        $this->buildAction()->execute($this->baseConfig(['attachmentMode' => 'einvoice_required']), $this->buildInvoice(), []);
    }

    public function testPreferredModeFallsBackToPlainPdfWhenGenerationFails(): void
    {
        $this->readinessService->method('check')->willReturn($this->readiness(true));
        $this->invoiceService->method('generateInvoicePdfXml')->willThrowException(new \RuntimeException('merge failed'));

        $this->useMailServiceMock();
        $this->mailService->expects(self::once())
            ->method('sendHTMLMail')
            ->with(
                'guest@example.com',
                self::anything(),
                self::anything(),
                self::callback(static function (array $attachments): bool {
                    return 1 === count($attachments)
                        && 'R-1.pdf' === $attachments[0]->getName()
                        && 'PLAIN_PDF_BYTES' === $attachments[0]->getBody();
                })
            );

        $message = $this->buildAction()->execute($this->baseConfig(), $this->buildInvoice(), []);

        self::assertStringContainsString('invoice_email_sent_fallback', $message);
    }

    public function testReadyInvoiceGetsHybridPdfAttached(): void
    {
        $this->readinessService->method('check')->willReturn($this->readiness(true));
        $this->invoiceService->method('generateInvoicePdfXml')->willReturn('HYBRID_PDF_BYTES');

        $this->useMailServiceMock();
        $this->mailService->expects(self::once())
            ->method('sendHTMLMail')
            ->with(
                'guest@example.com',
                self::anything(),
                self::anything(),
                self::callback(static function (array $attachments): bool {
                    return 1 === count($attachments)
                        && 'R-1_einvoice.pdf' === $attachments[0]->getName()
                        && 'HYBRID_PDF_BYTES' === $attachments[0]->getBody();
                })
            );

        $message = $this->buildAction()->execute($this->baseConfig(), $this->buildInvoice(), []);

        self::assertStringContainsString('invoice_email_sent', $message);
        self::assertStringNotContainsString('fallback', $message);
    }

    public function testAttachXmlAddsSecondAttachment(): void
    {
        $this->readinessService->method('check')->willReturn($this->readiness(true));
        $this->invoiceService->method('generateInvoicePdfXml')->willReturn('HYBRID_PDF_BYTES');
        $this->exportService->method('generateInvoiceData')->willReturn('<xml/>');

        $this->useMailServiceMock();
        $this->mailService->expects(self::once())
            ->method('sendHTMLMail')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (array $attachments): bool {
                    return 2 === count($attachments)
                        && 'R-1_einvoice.xml' === $attachments[1]->getName()
                        && 'text/xml' === $attachments[1]->getContentType();
                })
            );

        $this->buildAction()->execute($this->baseConfig(['attachXml' => 'yes']), $this->buildInvoice(), []);
    }

    public function testCorrespondenceIsPersistedPerReservation(): void
    {
        $this->readinessService->method('check')->willReturn($this->readiness(true));
        $this->invoiceService->method('generateInvoicePdfXml')->willReturn('HYBRID_PDF_BYTES');

        $invoice = $this->buildInvoice();
        $invoice->addReservation(new Reservation());

        $this->useEntityManagerMock();
        $persisted = [];
        $this->em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $this->em->expects(self::once())->method('flush');

        $this->buildAction()->execute($this->baseConfig(), $invoice, []);

        $files = array_filter($persisted, static fn (object $e): bool => $e instanceof FileCorrespondence);
        $mails = array_filter($persisted, static fn (object $e): bool => $e instanceof MailCorrespondence);

        self::assertCount(1, $files);
        self::assertCount(1, $mails);
    }
}
