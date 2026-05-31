<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AppSettings;
use App\Repository\AppSettingsRepository;
use App\Service\AppSettingsService;
use App\Service\SmtpPasswordCrypto;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class AppSettingsServiceTest extends TestCase
{
    public function testLegacyEnvMailSettingsAreAdoptedIntoEmptySettings(): void
    {
        $settings = new AppSettings();
        $crypto = new SmtpPasswordCrypto('test-secret');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $repository = $this->createMock(AppSettingsRepository::class);
        $repository->expects($this->once())->method('findSingleton')->willReturn($settings);

        $service = new AppSettingsService(
            $em,
            $repository,
            $crypto,
            'info@example.com',
            'Example Pension',
            'bounce@example.com',
            'true',
            'smtp://user%40example.com:p%40ss%20word@smtp.example.com:587?require_tls=true'
        );

        $resolved = $service->getSettings();

        self::assertSame($settings, $resolved);
        self::assertSame('info@example.com', $settings->getMailFromEmail());
        self::assertSame('Example Pension', $settings->getMailFromName());
        self::assertSame('bounce@example.com', $settings->getMailReturnPath());
        self::assertTrue($settings->getMailCopy());
        self::assertSame('smtp.example.com', $settings->getSmtpHost());
        self::assertSame(587, $settings->getSmtpPort());
        self::assertSame('starttls', $settings->getSmtpEncryption());
        self::assertSame('user@example.com', $settings->getSmtpUsername());
        self::assertSame('p@ss word', $crypto->decrypt($settings->getSmtpPasswordEncrypted()));
    }

    public function testLegacyEnvDoesNotOverwriteConfiguredMailSettings(): void
    {
        $settings = (new AppSettings())->setMailFromEmail('db@example.com');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $repository = $this->createMock(AppSettingsRepository::class);
        $repository->expects($this->once())->method('findSingleton')->willReturn($settings);

        $service = new AppSettingsService(
            $em,
            $repository,
            new SmtpPasswordCrypto('test-secret'),
            'info@example.com',
            'Example Pension',
            'bounce@example.com',
            'true',
            'smtp://user:pass@smtp.example.com:587'
        );

        $service->getSettings();

        self::assertSame('db@example.com', $settings->getMailFromEmail());
        self::assertNull($settings->getSmtpHost());
    }

    public function testMissingLegacyEnvDoesNotPersistEmptyMailDefaults(): void
    {
        $settings = new AppSettings();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $repository = $this->createMock(AppSettingsRepository::class);
        $repository->expects($this->once())->method('findSingleton')->willReturn($settings);

        $service = new AppSettingsService(
            $em,
            $repository,
            new SmtpPasswordCrypto('test-secret'),
            '',
            '',
            '',
            'true',
            'null://null'
        );

        $service->getSettings();

        self::assertNull($settings->getMailFromEmail());
        self::assertNull($settings->getSmtpHost());
    }
}
