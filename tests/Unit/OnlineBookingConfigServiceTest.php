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

    /** Ensure null template ID returns null without querying the repository. */
    public function testGetConfirmationEmailTemplateReturnsNullWhenNoTemplateIdConfigured(): void
    {
        $config = new OnlineBookingConfig();

        $em = $this->createStub(EntityManagerInterface::class);

        $service = new OnlineBookingConfigService(
            $em,
            $this->createStub(OnlineBookingConfigRepository::class),
            $this->createStub(SubsidiaryRepository::class),
            $this->createStub(AppartmentRepository::class)
        );

        self::assertNull($service->getConfirmationEmailTemplate($config));
    }

    /** Ensure ALL mode delegates to loadAllIds on the subsidiary repository. */
    public function testGetAllowedSubsidiaryIdsInAllMode(): void
    {
        $config = new OnlineBookingConfig();
        $config->setSubsidiariesMode(OnlineBookingConfig::SUBSIDIARIES_MODE_ALL);

        $subsidiaryRepo = $this->createStub(SubsidiaryRepository::class);
        $subsidiaryRepo->method('loadAllIds')->willReturn([1, 2, 3]);

        $service = new OnlineBookingConfigService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(OnlineBookingConfigRepository::class),
            $subsidiaryRepo,
            $this->createStub(AppartmentRepository::class)
        );

        self::assertSame([1, 2, 3], $service->getAllowedSubsidiaryIds($config));
    }

    /** Ensure SELECTED mode delegates to loadExistingIds with the configured IDs. */
    public function testGetAllowedSubsidiaryIdsInSelectedMode(): void
    {
        $config = new OnlineBookingConfig();
        $config->setSubsidiariesMode(OnlineBookingConfig::SUBSIDIARIES_MODE_SELECTED);
        $config->setSelectedSubsidiaryIds([10, 20]);

        $subsidiaryRepo = $this->createMock(SubsidiaryRepository::class);
        $subsidiaryRepo->expects(self::once())
            ->method('loadExistingIds')
            ->with([10, 20])
            ->willReturn([10]);

        $service = new OnlineBookingConfigService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(OnlineBookingConfigRepository::class),
            $subsidiaryRepo,
            $this->createStub(AppartmentRepository::class)
        );

        self::assertSame([10], $service->getAllowedSubsidiaryIds($config));
    }

    /** Ensure ALL mode delegates to loadAllIds on the room repository. */
    public function testGetAllowedRoomIdsInAllMode(): void
    {
        $config = new OnlineBookingConfig();
        $config->setRoomsMode(OnlineBookingConfig::ROOMS_MODE_ALL);

        $roomRepo = $this->createStub(AppartmentRepository::class);
        $roomRepo->method('loadAllIds')->willReturn([5, 6]);

        $service = new OnlineBookingConfigService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(OnlineBookingConfigRepository::class),
            $this->createStub(SubsidiaryRepository::class),
            $roomRepo
        );

        self::assertSame([5, 6], $service->getAllowedRoomIds($config));
    }

    /** Ensure SELECTED mode delegates to loadExistingIds with the configured room IDs. */
    public function testGetAllowedRoomIdsInSelectedMode(): void
    {
        $config = new OnlineBookingConfig();
        $config->setRoomsMode(OnlineBookingConfig::ROOMS_MODE_SELECTED);
        $config->setSelectedRoomIds([7, 8, 9]);

        $roomRepo = $this->createMock(AppartmentRepository::class);
        $roomRepo->expects(self::once())
            ->method('loadExistingIds')
            ->with([7, 8, 9])
            ->willReturn([7, 9]);

        $service = new OnlineBookingConfigService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(OnlineBookingConfigRepository::class),
            $this->createStub(SubsidiaryRepository::class),
            $roomRepo
        );

        self::assertSame([7, 9], $service->getAllowedRoomIds($config));
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
