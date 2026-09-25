<?php

declare(strict_types=1);

namespace App\Tests\Unit\GuestCheckIn;

use App\Entity\Appartment;
use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Repository\GuestCheckInRepository;
use App\Service\GuestCheckIn\GuestCheckInConfigService;
use App\Service\GuestCheckIn\GuestCheckInPolicy;
use App\Workflow\Condition\ReservationGuestCheckInCondition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ReservationGuestCheckInConditionTest extends TestCase
{
    public function testOpenWhileTheGuestCanStillCheckIn(): void
    {
        $condition = $this->condition(null, true);

        self::assertTrue($condition->evaluate(['state' => 'open'], $this->reservation(), []));
        self::assertFalse($condition->evaluate(['state' => 'completed'], $this->reservation(), []));
    }

    public function testNeverOpenWhileTheFeatureIsOff(): void
    {
        self::assertFalse($this->condition(null, false)->evaluate(['state' => 'open'], $this->reservation(), []));
    }

    public function testCompletedOnceTheGuestSentTheForm(): void
    {
        $reservation = $this->reservation();
        $checkIn = new GuestCheckIn($reservation, 'selector');
        $checkIn->recordSubmission(['v' => 1], new \DateTimeImmutable());
        $condition = $this->condition($checkIn, true);

        self::assertTrue($condition->evaluate(['state' => 'completed'], $reservation, []));
        self::assertFalse($condition->evaluate(['state' => 'open'], $reservation, []));
    }

    public function testTakenOverCountsAsCompleted(): void
    {
        $reservation = $this->reservation();
        $checkIn = new GuestCheckIn($reservation, 'selector');
        $checkIn->markApplied(new \DateTimeImmutable());

        self::assertTrue($this->condition($checkIn, true)->evaluate(['state' => 'completed'], $reservation, []));
    }

    private function condition(?GuestCheckIn $checkIn, bool $enabled): ReservationGuestCheckInCondition
    {
        $repository = $this->createStub(GuestCheckInRepository::class);
        $repository->method('findOneByReservation')->willReturn($checkIn);
        $config = $this->createStub(GuestCheckInConfigService::class);
        $config->method('isEnabled')->willReturn($enabled);

        return new ReservationGuestCheckInCondition($repository, $config, new GuestCheckInPolicy(new MockClock('2026-09-20 12:00', date_default_timezone_get())));
    }

    private function reservation(): Reservation
    {
        $reservation = new Reservation();
        (new \ReflectionProperty(Reservation::class, 'id'))->setValue($reservation, 7);
        $reservation->setStartDate(new \DateTime('2026-10-10'));
        $reservation->setEndDate(new \DateTime('2026-10-12'));
        $reservation->setAppartment(new Appartment());
        $reservation->setReservationStatus(new ReservationStatus());

        return $reservation;
    }
}
