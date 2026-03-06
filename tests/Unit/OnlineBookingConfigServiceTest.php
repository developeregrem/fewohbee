<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\OnlineBookingConfig;
use App\Entity\Template;
use App\Entity\TemplateType;
use App\Repository\AppartmentRepository;
use App\Repository\OnlineBookingConfigRepository;
use App\Repository\SubsidiaryRepository;
use App\Repository\TemplateRepository;
use App\Service\OnlineBookingConfigService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class OnlineBookingConfigServiceTest extends TestCase
{
    /** Ensure only reservation email templates are accepted as confirmation templates. */
    public function testGetConfirmationEmailTemplateReturnsOnlyReservationEmailTemplates(): void
    {
        $config = new OnlineBookingConfig();
        $config->setConfirmationEmailTemplateId(123);

        $templateRepo = $this->createMock(TemplateRepository::class);
        $templateRepo->expects(self::exactly(2))
            ->method('find')
            ->with(123)
            ->willReturnOnConsecutiveCalls(
                $this->createTemplateWithType('TEMPLATE_INVOICE_PDF'),
                $this->createTemplateWithType('TEMPLATE_RESERVATION_EMAIL')
            );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')
            ->willReturn($templateRepo);

        $service = new OnlineBookingConfigService(
            $em,
            $this->createStub(OnlineBookingConfigRepository::class),
            $this->createStub(SubsidiaryRepository::class),
            $this->createStub(AppartmentRepository::class)
        );

        self::assertNull($service->getConfirmationEmailTemplate($config));

        $validTemplate = $service->getConfirmationEmailTemplate($config);
        self::assertInstanceOf(Template::class, $validTemplate);
        self::assertSame('TEMPLATE_RESERVATION_EMAIL', $validTemplate->getTemplateType()?->getName());
    }

    /** Ensure invalid or missing template IDs result in a null confirmation template. */
    public function testGetConfirmationEmailTemplateReturnsNullForMissingTemplate(): void
    {
        $config = new OnlineBookingConfig();
        $config->setConfirmationEmailTemplateId(999);

        $templateRepo = $this->createMock(TemplateRepository::class);
        $templateRepo->expects(self::once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')
            ->willReturn($templateRepo);

        $service = new OnlineBookingConfigService(
            $em,
            $this->createStub(OnlineBookingConfigRepository::class),
            $this->createStub(SubsidiaryRepository::class),
            $this->createStub(AppartmentRepository::class)
        );

        self::assertNull($service->getConfirmationEmailTemplate($config));
    }

    /** Create a minimal template instance with a named template type for type filtering tests. */
    private function createTemplateWithType(string $typeName): Template
    {
        $type = new TemplateType();
        $type->setName($typeName);
        $type->setIcon('fa-file');

        $template = new Template();
        $template->setName('Test');
        $template->setText('Body');
        $template->setTemplateType($type);

        return $template;
    }
}
