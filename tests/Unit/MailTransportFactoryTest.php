<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AppSettings;
use App\Service\MailTransportFactory;
use App\Service\SmtpPasswordCrypto;
use PHPUnit\Framework\TestCase;

final class MailTransportFactoryTest extends TestCase
{
    public function testBuildDsnUsesGuidedSmtpFieldsAndEncodesCredentials(): void
    {
        $crypto = new SmtpPasswordCrypto('test-secret');
        $settings = (new AppSettings())
            ->setSmtpHost('smtp.example.com')
            ->setSmtpPort(587)
            ->setSmtpEncryption('starttls')
            ->setSmtpUsername('user@example.com')
            ->setSmtpPasswordEncrypted($crypto->encrypt('p@ss word'));

        $factory = new MailTransportFactory($crypto);

        self::assertSame(
            'smtp://user%40example.com:p%40ss%20word@smtp.example.com:587?require_tls=true',
            $factory->buildDsn($settings)
        );
    }

    public function testBuildDsnSupportsSslAndNoEncryption(): void
    {
        $factory = new MailTransportFactory(new SmtpPasswordCrypto('test-secret'));

        $sslSettings = (new AppSettings())
            ->setSmtpHost('smtp.example.com')
            ->setSmtpPort(465)
            ->setSmtpEncryption('ssl');
        self::assertSame('smtps://smtp.example.com:465', $factory->buildDsn($sslSettings));

        $plainSettings = (new AppSettings())
            ->setSmtpHost('smtp.example.com')
            ->setSmtpPort(25)
            ->setSmtpEncryption('none');
        self::assertSame('smtp://smtp.example.com:25?auto_tls=false', $factory->buildDsn($plainSettings));
    }

    public function testBuildDsnUsesNullTransportWithoutHost(): void
    {
        $factory = new MailTransportFactory(new SmtpPasswordCrypto('test-secret'));

        self::assertSame('null://null', $factory->buildDsn(new AppSettings()));
    }
}
