<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\OnlineBookingConfig;
use App\Repository\AppartmentRepository;
use App\Repository\OnlineBookingConfigRepository;
use App\Repository\SubsidiaryRepository;
use App\Service\OnlineBookingConfigService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class OnlineBookingConfigServiceTest extends TestCase
{
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

    public function testLegacyFrameAncestorsAreAdoptedIntoEmptyConfig(): void
    {
        $config = new OnlineBookingConfig();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $repository = $this->createMock(OnlineBookingConfigRepository::class);
        $repository->expects(self::once())->method('findSingleton')->willReturn($config);

        $service = new OnlineBookingConfigService(
            $em,
            $repository,
            $this->createStub(SubsidiaryRepository::class),
            $this->createStub(AppartmentRepository::class),
            'https://www.example.com https://example.com/path'
        );

        $resolvedConfig = $service->getConfig();

        self::assertSame($config, $resolvedConfig);
        self::assertSame("https://www.example.com\nhttps://example.com", $config->getAllowedEmbeddingOrigins());
        self::assertSame('https://www.example.com https://example.com', $service->getAllowedEmbeddingOriginsForCsp($config));
    }

    public function testMissingLegacyFrameAncestorsDoNotPersistEmptyConfig(): void
    {
        $config = new OnlineBookingConfig();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $repository = $this->createMock(OnlineBookingConfigRepository::class);
        $repository->expects(self::once())->method('findSingleton')->willReturn($config);

        $service = new OnlineBookingConfigService(
            $em,
            $repository,
            $this->createStub(SubsidiaryRepository::class),
            $this->createStub(AppartmentRepository::class),
            ''
        );

        $service->getConfig();

        self::assertNull($config->getAllowedEmbeddingOrigins());
    }
}
