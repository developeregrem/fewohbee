<?php

declare(strict_types=1);

namespace App\Tests\Unit\GuestCheckIn;

use App\Dto\GuestCheckIn\GuestCheckInCompanion;
use App\Dto\GuestCheckIn\GuestCheckInSubmission;
use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use App\Event\GuestCheckInSubmittedEvent;
use App\Service\GuestCheckIn\GuestCheckInSubmissionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Translation\LocaleSwitcher;

final class GuestCheckInSubmissionServiceTest extends TestCase
{
    public function testSubmissionSetsTheArrivalTimeAndDropsEmptyCompanions(): void
    {
        $checkIn = new GuestCheckIn(new Reservation(), 'selector');
        $submission = $this->submission();
        $submission->companions = [new GuestCheckInCompanion(), $this->companion('Max')];

        $this->service()->submit($checkIn, $submission, 'en');

        self::assertSame('18:30', $checkIn->getReservation()->getArrivalTime()?->format('H:i'));
        $payload = $checkIn->getPayload() ?? [];
        self::assertSame('en', $payload['locale']);
        self::assertCount(1, $payload['companions']);
        self::assertSame('Max', $payload['companions'][0]['firstname']);
        self::assertNull($payload['companions'][0]['address'], 'No own address: lives with the main guest.');
    }

    public function testEmptyIdNumberKeepsThePreviousOne(): void
    {
        $checkIn = new GuestCheckIn(new Reservation(), 'selector');
        $first = $this->submission();
        $first->mainGuest->idNumber = 'P1234567';
        $service = $this->service();
        $service->submit($checkIn, $first, 'de');

        $service->submit($checkIn, $this->submission(), 'de');

        self::assertSame('P1234567', $checkIn->getPayload()['mainGuest']['idNumber'] ?? null);
    }

    public function testControlCharactersAreRemoved(): void
    {
        $checkIn = new GuestCheckIn(new Reservation(), 'selector');
        $submission = $this->submission();
        $submission->mainGuest->lastname = "Mül\u{200B}ler\x07";
        $submission->message = "Line one\nLine two\x00";

        $this->service()->submit($checkIn, $submission, 'de');

        self::assertSame('Müller', $checkIn->getPayload()['mainGuest']['lastname'] ?? null);
        self::assertSame("Line one\nLine two", $checkIn->getPayload()['message'] ?? null);
    }

    public function testEventIsDispatchedAfterFlushInTheInstallationLanguage(): void
    {
        $calls = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'flush';
        });
        $switcher = $this->createMock(LocaleSwitcher::class);
        $switcher->expects($this->once())->method('runWithLocale')->with('de')->willReturnCallback(static fn (string $locale, callable $callback): mixed => $callback());
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')->willReturnCallback(static function (object $event) use (&$calls): object {
            self::assertInstanceOf(GuestCheckInSubmittedEvent::class, $event);
            self::assertTrue($event->firstSubmission);
            $calls[] = 'dispatch';

            return $event;
        });

        $service = new GuestCheckInSubmissionService($em, $dispatcher, $switcher, new MockClock(), 'de');
        $service->submit(new GuestCheckIn(new Reservation(), 'selector'), $this->submission(), 'en');

        self::assertSame(['flush', 'dispatch'], $calls);
    }

    private function service(): GuestCheckInSubmissionService
    {
        $switcher = $this->createStub(LocaleSwitcher::class);
        $switcher->method('runWithLocale')->willReturnCallback(static fn (string $locale, callable $callback): mixed => $callback());

        return new GuestCheckInSubmissionService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $switcher,
            new MockClock(),
            'de',
        );
    }

    private function submission(): GuestCheckInSubmission
    {
        $submission = new GuestCheckInSubmission();
        $submission->arrivalTime = '18:30';
        $submission->mainGuest->salutation = 'Ms';
        $submission->mainGuest->firstname = 'Anna';
        $submission->mainGuest->lastname = 'Müller';

        return $submission;
    }

    private function companion(string $firstname): GuestCheckInCompanion
    {
        $companion = new GuestCheckInCompanion();
        $companion->firstname = $firstname;
        $companion->lastname = 'Müller';

        return $companion;
    }
}
