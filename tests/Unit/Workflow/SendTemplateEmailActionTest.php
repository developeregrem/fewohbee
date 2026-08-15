<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Entity\Correspondence;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\FileCorrespondence;
use App\Entity\Invoice;
use App\Entity\MailAttachment;
use App\Entity\MailCorrespondence;
use App\Entity\Reservation;
use App\Entity\Template;
use App\Entity\TemplateType;
use App\Repository\TemplateRepository;
use App\Service\AppSettingsService;
use App\Service\MailService;
use App\Service\TemplatesService;
use App\Workflow\Action\SendTemplateEmailAction;
use App\Workflow\Attachment\ResolvedAttachment;
use App\Workflow\Attachment\ResolvedAttachmentSet;
use App\Workflow\Attachment\WorkflowAttachmentResolver;
use App\Workflow\WorkflowSkippedException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SendTemplateEmailActionTest extends TestCase
{
    private TemplatesService $templatesService;
    private MailService $mailService;
    private AppSettingsService $settingsService;
    private WorkflowAttachmentResolver $attachmentResolver;
    private EntityManagerInterface $em;
    private TranslatorInterface $translator;
    private TemplateRepository $repository;
    private Template $template;

    protected function setUp(): void
    {
        $this->templatesService = $this->createStub(TemplatesService::class);
        $this->mailService = $this->createStub(MailService::class);
        $this->settingsService = $this->createStub(AppSettingsService::class);
        $this->attachmentResolver = $this->createStub(WorkflowAttachmentResolver::class);
        $this->attachmentResolver->method('resolve')->willReturn(new ResolvedAttachmentSet());
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->translator = $this->createStub(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);

        $this->template = $this->buildTemplate('Willkommen', 'TEMPLATE_RESERVATION_EMAIL', 5);

        $this->repository = $this->createStub(TemplateRepository::class);
        $this->repository->method('find')->willReturn($this->template);
        $this->em->method('getRepository')->willReturn($this->repository);

        $this->templatesService->method('renderTemplate')->willReturn('<html>body</html>');
        $this->templatesService->method('renderTemplateSubject')->willReturn('Willkommen!');
    }

    private function buildTemplate(string $name, string $typeName, int $id): Template
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

    private function buildAction(): SendTemplateEmailAction
    {
        return new SendTemplateEmailAction(
            $this->templatesService,
            $this->mailService,
            $this->settingsService,
            $this->attachmentResolver,
            $this->em,
            $this->translator,
        );
    }

    private function baseConfig(array $overrides = []): array
    {
        return array_merge([
            'templateId' => 5,
            'recipientType' => 'custom',
            'customRecipient' => 'gast@example.com',
        ], $overrides);
    }

    private function buildAttachment(string $name = 'AGB'): ResolvedAttachment
    {
        return new ResolvedAttachment(
            new MailAttachment('PDF_BYTES', $name.'.pdf', 'application/pdf'),
            $name,
            $this->buildTemplate($name, 'TEMPLATE_FILE_PDF', 12),
            '<html>agb</html>',
        );
    }

    public function testConfiguredAttachmentsAreSent(): void
    {
        $attachment = $this->buildAttachment();
        $this->attachmentResolver = $this->createStub(WorkflowAttachmentResolver::class);
        $this->attachmentResolver->method('resolve')->willReturn(new ResolvedAttachmentSet([$attachment]));

        $this->mailService = $this->createMock(MailService::class);
        $this->mailService->expects(self::once())
            ->method('sendHTMLMail')
            ->with(
                'gast@example.com',
                'Willkommen!',
                '<html>body</html>',
                self::callback(static fn (array $attachments): bool => 1 === count($attachments)
                    && 'AGB.pdf' === $attachments[0]->getName())
            );

        $config = $this->baseConfig(['attachments' => [['type' => 'pdf_template', 'templateId' => 12]]]);
        $message = $this->buildAction()->execute($config, new Reservation(), []);

        self::assertStringContainsString('email_sent_with_attachments', $message);
    }

    public function testConfigWithoutAttachmentsStaysBackwardsCompatible(): void
    {
        $this->attachmentResolver = $this->createMock(WorkflowAttachmentResolver::class);
        $this->attachmentResolver->expects(self::once())
            ->method('resolve')
            ->with([], self::anything(), self::anything(), 'skip_missing')
            ->willReturn(new ResolvedAttachmentSet());

        $this->mailService = $this->createMock(MailService::class);
        $this->mailService->expects(self::once())
            ->method('sendHTMLMail')
            ->with(self::anything(), self::anything(), self::anything(), []);

        $message = $this->buildAction()->execute($this->baseConfig(), new Reservation(), []);

        self::assertStringContainsString('email_sent', $message);
        self::assertStringNotContainsString('with_attachments', $message);
    }

    public function testBookerRecipientSendsOnlyToBookerEmail(): void
    {
        $reservation = $this->reservationWithBookerEmail('guest@example.com');

        $this->mailService = $this->createMock(MailService::class);
        $this->mailService->expects(self::once())
            ->method('sendHTMLMail')
            ->with('guest@example.com', 'Willkommen!', '<html>body</html>', []);

        $this->buildAction()->execute(
            $this->baseConfig(['recipientType' => 'booker_email']),
            $reservation,
            [],
        );
    }

    public function testNotificationRecipientDoesNotSendToBookerEmail(): void
    {
        $reservation = $this->reservationWithBookerEmail('guest@example.com');
        $this->settingsService->method('getNotificationEmail')->willReturn('owner@example.com');

        $this->mailService = $this->createMock(MailService::class);
        $this->mailService->expects(self::once())
            ->method('sendHTMLMail')
            ->with('owner@example.com', 'Willkommen!', '<html>body</html>', []);

        $this->buildAction()->execute(
            $this->baseConfig(['recipientType' => 'notification_email']),
            $reservation,
            [],
        );
    }

    public function testAttachmentsArePersistedAsCorrespondenceChildren(): void
    {
        $attachment = $this->buildAttachment();
        $file = new FileCorrespondence();

        $this->attachmentResolver = $this->createStub(WorkflowAttachmentResolver::class);
        $this->attachmentResolver->method('resolve')->willReturn(new ResolvedAttachmentSet([$attachment]));
        $this->attachmentResolver->method('createFileCorrespondence')->willReturn($file);

        $persisted = [];
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getRepository')->willReturn($this->repository);
        $this->em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $this->em->expects(self::once())->method('flush');

        $config = $this->baseConfig(['attachments' => [['type' => 'pdf_template', 'templateId' => 12]]]);
        $this->buildAction()->execute($config, new Reservation(), []);

        $mails = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof MailCorrespondence));
        self::assertCount(1, $mails);
        self::assertContains($file, $persisted);
        self::assertTrue($mails[0]->getChildren()->contains($file));
    }

    public function testOneCorrespondencePerReservationOfAGroupBooking(): void
    {
        $reservations = [new Reservation(), new Reservation()];

        $persisted = [];
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->em->method('getRepository')->willReturn($this->repository);
        $this->em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->buildAction()->execute(
            $this->baseConfig(),
            $reservations[0],
            ['allReservations' => $reservations]
        );

        self::assertCount(2, array_filter($persisted, static fn (object $e): bool => $e instanceof MailCorrespondence));
    }

    public function testInvoiceMailIsRecordedForEveryLinkedReservation(): void
    {
        $this->template = $this->buildTemplate('Mahnung', 'TEMPLATE_INVOICE_EMAIL', 5);
        $this->repository = $this->createStub(TemplateRepository::class);
        $this->repository->method('find')->willReturn($this->template);

        $invoice = new Invoice();
        $invoice->addReservation(new Reservation());
        $invoice->addReservation(new Reservation());
        $invoice->setEmail('rechnung@example.com');

        $persisted = [];
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->em->method('getRepository')->willReturn($this->repository);
        $this->em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->buildAction()->execute($this->baseConfig(['recipientType' => 'invoice_email']), $invoice, []);

        $mails = array_filter($persisted, static fn (object $e): bool => $e instanceof MailCorrespondence);
        self::assertCount(2, $mails);
        foreach ($mails as $mail) {
            self::assertInstanceOf(Correspondence::class, $mail);
            self::assertSame('rechnung@example.com', $mail->getRecipient());
        }
    }

    public function testResolverSkipPropagates(): void
    {
        $this->attachmentResolver = $this->createStub(WorkflowAttachmentResolver::class);
        $this->attachmentResolver->method('resolve')
            ->willThrowException(new WorkflowSkippedException('workflow.log.skipped_attachment_missing'));

        $this->expectException(WorkflowSkippedException::class);
        $this->buildAction()->execute($this->baseConfig(), new Reservation(), []);
    }

    public function testWarningsAreAppendedToTheLogSummary(): void
    {
        $this->attachmentResolver = $this->createStub(WorkflowAttachmentResolver::class);
        $this->attachmentResolver->method('resolve')
            ->willReturn(new ResolvedAttachmentSet([], ['workflow.log.attachment_no_invoice']));

        $message = $this->buildAction()->execute($this->baseConfig(), new Reservation(), []);

        self::assertStringContainsString('attachment_warnings', $message);
    }

    private function reservationWithBookerEmail(string $email): Reservation
    {
        $address = new CustomerAddresses();
        $address->setEmail($email);

        $booker = new Customer();
        $booker->addCustomerAddress($address);

        $reservation = new Reservation();
        $reservation->setBooker($booker);

        return $reservation;
    }
}
