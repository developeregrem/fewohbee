<?php

declare(strict_types=1);

namespace App\Tests\Unit\GuestCheckIn;

use App\Entity\Appartment;
use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Service\GuestCheckIn\GuestCheckInLinkState;
use App\Service\GuestCheckIn\GuestCheckInPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class GuestCheckInPolicyTest extends TestCase
{
    /** Stay from 2026-10-10 to 2026-10-12. */
    #[DataProvider('timelineCases')]
    public function testLinkStateAlongTheStay(string $now, GuestCheckInLinkState $expected): void
    {
        $policy = new GuestCheckInPolicy(new MockClock($now, date_default_timezone_get()));

        self::assertSame($expected, $policy->linkState($this->reservation(), null, true));
    }

    /** @return iterable<string, array{0: string, 1: GuestCheckInLinkState}> */
    public static function timelineCases(): iterable
    {
        yield 'weeks before' => ['2026-09-20 12:00', GuestCheckInLinkState::EDITABLE];
        yield 'arrival day late evening' => ['2026-10-10 23:59', GuestCheckInLinkState::EDITABLE];
        yield 'day after arrival' => ['2026-10-11 00:01', GuestCheckInLinkState::LOCKED];
        yield 'departure day' => ['2026-10-12 18:00', GuestCheckInLinkState::LOCKED];
        yield 'after departure' => ['2026-10-13 08:00', GuestCheckInLinkState::UNAVAILABLE];
    }

    public function testAppliedCheckInIsLockedBeforeArrival(): void
    {
        $reservation = $this->reservation();
        $checkIn = new GuestCheckIn($reservation, 'selector');
        $checkIn->markApplied(new \DateTimeImmutable());

        self::assertSame(GuestCheckInLinkState::LOCKED, $this->policy()->linkState($reservation, $checkIn, true));
    }

    public function testNothingIsOfferedWhileTheFeatureIsOff(): void
    {
        self::assertSame(GuestCheckInLinkState::UNAVAILABLE, $this->policy()->linkState($this->reservation(), null, false));
    }

    public function testCancelledReservationsGetNoLink(): void
    {
        $reservation = $this->reservation();
        $reservation->setReservationStatus((new ReservationStatus())->setIsBlocking(false));

        self::assertSame(GuestCheckInLinkState::UNAVAILABLE, $this->policy()->linkState($reservation, null, true));
    }

    public function testUnsavedReservationGetsNoLink(): void
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-10-10'));
        $reservation->setEndDate(new \DateTime('2026-10-12'));
        $reservation->setAppartment(new Appartment());

        self::assertSame(GuestCheckInLinkState::UNAVAILABLE, $this->policy()->linkState($reservation, null, true));
    }

    public function testCompanionCountLeavesOutTheMainGuestAndIsCapped(): void
    {
        $policy = $this->policy();
        $reservation = $this->reservation();

        $reservation->setPersons(1);
        self::assertSame(0, $policy->companionCount($reservation));
        $reservation->setPersons(4);
        self::assertSame(3, $policy->companionCount($reservation));
        $reservation->setPersons(60);
        self::assertSame(GuestCheckInPolicy::MAX_COMPANIONS, $policy->companionCount($reservation));
    }

    public function testInfantsAreFellowTravellersToo(): void
    {
        $reservation = $this->reservation();
        // One adult and one child count for the occupancy, the infant does not — but stays.
        $reservation->setPersons(2);
        $reservation->setGuestCounts([1 => 1, 2 => 1, 3 => 1]);

        self::assertSame(3, $reservation->getTotalGuests());
        self::assertSame(2, $this->policy()->companionCount($reservation));
    }

    private function policy(): GuestCheckInPolicy
    {
        return new GuestCheckInPolicy(new MockClock('2026-09-20 12:00', date_default_timezone_get()));
    }

    private function reservation(): Reservation
    {
        $reservation = new Reservation();
        (new \ReflectionProperty(Reservation::class, 'id'))->setValue($reservation, 42);
        $reservation->setStartDate(new \DateTime('2026-10-10'));
        $reservation->setEndDate(new \DateTime('2026-10-12'));
        $reservation->setAppartment(new Appartment());
        $reservation->setPersons(2);
        $reservation->setReservationStatus(new ReservationStatus());

        return $reservation;
    }
}
