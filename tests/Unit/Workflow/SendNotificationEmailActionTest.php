<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Entity\Appartment;
use App\Entity\Enum\RoomCategoryKind;
use App\Entity\Reservation;
use App\Entity\RoomCategory;
use App\Service\AppSettingsService;
use App\Service\MailService;
use App\Workflow\Action\SendNotificationEmailAction;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SendNotificationEmailActionTest extends TestCase
{
    public function testEventIsAnnouncedWithItsLastDay(): void
    {
        $hall = (new Appartment())->setRoomCategory((new RoomCategory())->setKind(RoomCategoryKind::EVENT_SPACE));
        $hall->setNumber('Saal');
        $event = new Reservation();
        $event->setAppartment($hall);
        $event->setStartDate(new \DateTime('2030-01-12'));
        $event->setEndDate(new \DateTime('2030-01-13'));
        $event->setPersons(40);

        $sent = '';
        $mail = $this->createStub(MailService::class);
        $mail->method('sendHTMLMail')->willReturnCallback(static function (string $to, string $subject, string $body) use (&$sent): void {
            $sent = $body;
        });
        $settings = $this->createStub(AppSettingsService::class);
        $settings->method('getNotificationEmail')->willReturn('office@example.com');

        (new SendNotificationEmailAction($settings, $mail, $this->createStub(TranslatorInterface::class), $this->createStub(UrlGeneratorInterface::class)))
            ->execute([], $event, []);

        self::assertStringContainsString('12.01.2030', $sent);
        self::assertStringNotContainsString('13.01.2030', $sent);
    }
}
