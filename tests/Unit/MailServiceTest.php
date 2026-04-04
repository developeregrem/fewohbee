<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\MailService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class MailServiceTest extends TestCase
{
    public function testSendHtmlMailAddsPlainTextAndReturnPath(): void
    {
        $captured = null;

        $mailer = $this->createMock(MailerInterface::class);
        $mailer
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(function (RawMessage $message) use (&$captured): bool {
                $captured = $message;

                return $message instanceof Email;
            }));

        $service = new MailService(
            'info@fewohbee.de',
            'FewohBee Test',
            'bounce@fewohbee.de',
            'false',
            $mailer
        );

        $service->sendHTMLMail(
            'guest@example.com',
            'Rechnung',
            '<p>Hallo<br>Welt</p><table><tr><td>Position</td></tr></table>'
        );

        self::assertInstanceOf(Email::class, $captured);
        self::assertSame('Rechnung', $captured->getSubject());
        self::assertStringContainsString('<p>Hallo', $captured->getHtmlBody() ?? '');
        self::assertNotNull($captured->getTextBody());
        self::assertStringContainsString('Hallo', $captured->getTextBody() ?? '');
        self::assertStringContainsString('Welt', $captured->getTextBody() ?? '');
        self::assertSame('bounce@fewohbee.de', $captured->getReturnPath()?->getAddress());
    }

    public function testSendHtmlMailOmitsReturnPathWhenEmpty(): void
    {
        $captured = null;

        $mailer = $this->createMock(MailerInterface::class);
        $mailer
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(function (RawMessage $message) use (&$captured): bool {
                $captured = $message;

                return $message instanceof Email;
            }));

        $service = new MailService(
            'info@fewohbee.de',
            'FewohBee Test',
            '',
            'false',
            $mailer
        );

        $service->sendHTMLMail(
            'guest@example.com',
            'Test',
            '<p>Nur Inhalt</p>'
        );

        self::assertInstanceOf(Email::class, $captured);
        self::assertNull($captured->getReturnPath());
        self::assertEquals(
            [new Address('guest@example.com')],
            $captured->getTo()
        );
    }
}
