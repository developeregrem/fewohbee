<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AppSettings;
use App\Service\AppSettingsService;
use App\Service\MailService;
use App\Service\MailTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class MailServiceTest extends TestCase
{
    public function testSendHtmlMailUsesDbSettingsAndAddsPlainTextReturnPathAndCopy(): void
    {
        $settings = new AppSettings();
        $captured = null;

        $appSettingsService = $this->createMock(AppSettingsService::class);
        $appSettingsService->method('getSettings')->willReturn($settings);
        $appSettingsService->method('getEffectiveMailFromEmail')->with($settings)->willReturn('info@fewohbee.de');
        $appSettingsService->method('getEffectiveMailFromName')->with($settings)->willReturn('FewohBee Test');
        $appSettingsService->method('getEffectiveMailReturnPath')->with($settings)->willReturn('bounce@fewohbee.de');
        $appSettingsService->method('isEffectiveMailCopyEnabled')->with($settings)->willReturn(true);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(function (RawMessage $message) use (&$captured): bool {
                $captured = $message;

                return $message instanceof Email;
            }));

        $transportFactory = $this->createMock(MailTransportFactory::class);
        $transportFactory->expects($this->once())->method('createMailer')->with($settings)->willReturn($mailer);

        $service = new MailService($appSettingsService, $transportFactory, true);
        $service->sendHTMLMail('guest@example.com', 'Rechnung', '<p>Hallo<br>Welt</p>');

        self::assertInstanceOf(Email::class, $captured);
        self::assertSame('Rechnung', $captured->getSubject());
        self::assertEquals([new Address('info@fewohbee.de', 'FewohBee Test')], $captured->getFrom());
        self::assertEquals([new Address('guest@example.com')], $captured->getTo());
        self::assertEquals([new Address('info@fewohbee.de')], $captured->getBcc());
        self::assertSame('bounce@fewohbee.de', $captured->getReturnPath()?->getAddress());
        self::assertStringContainsString('Hallo', $captured->getTextBody() ?? '');
        self::assertStringContainsString('Welt', $captured->getTextBody() ?? '');
    }

    public function testSendHtmlMailSkipsTransportWhenMailIsDisabled(): void
    {
        $appSettingsService = $this->createMock(AppSettingsService::class);
        $appSettingsService->expects($this->never())->method('getSettings');

        $transportFactory = $this->createMock(MailTransportFactory::class);
        $transportFactory->expects($this->never())->method('createMailer');

        $service = new MailService($appSettingsService, $transportFactory, false);
        $service->sendHTMLMail('guest@example.com', 'Test', '<p>Body</p>');
    }

    public function testSendHtmlMailSkipsTransportWhenSenderIsNotConfigured(): void
    {
        $settings = new AppSettings();

        $appSettingsService = $this->createMock(AppSettingsService::class);
        $appSettingsService->expects($this->once())->method('getSettings')->willReturn($settings);
        $appSettingsService->expects($this->once())->method('getEffectiveMailFromEmail')->with($settings)->willReturn(null);

        $transportFactory = $this->createMock(MailTransportFactory::class);
        $transportFactory->expects($this->never())->method('createMailer');

        $service = new MailService($appSettingsService, $transportFactory, true);
        $service->sendHTMLMail('guest@example.com', 'Test', '<p>Body</p>');
    }
}
