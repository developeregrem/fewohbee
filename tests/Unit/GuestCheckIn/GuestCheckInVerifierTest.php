<?php

declare(strict_types=1);

namespace App\Tests\Unit\GuestCheckIn;

use App\Dto\GuestCheckIn\GuestCheckInVerification;
use App\Entity\Customer;
use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use App\Service\GuestCheckIn\GuestCheckInTokenSigner;
use App\Service\GuestCheckIn\GuestCheckInVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;

final class GuestCheckInVerifierTest extends TestCase
{
    #[DataProvider('acceptedNames')]
    public function testNameSpellingVariantsAreAccepted(string $stored, string $entered): void
    {
        self::assertTrue($this->verifier()->matches($this->reservation($stored), $this->input($entered)));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function acceptedNames(): iterable
    {
        yield 'case' => ['Müller', 'MÜLLER'];
        yield 'umlaut transliterated' => ['Müller', 'Mueller'];
        yield 'accent stripped' => ['Müller', 'Muller'];
        yield 'sharp s' => ['Weiß', 'Weiss'];
        yield 'hyphen and spaces' => ['Meyer-Lüdenscheid', ' meyer  lüdenscheid '];
        yield 'french accent' => ['Lefèvre', 'Lefevre'];
    }

    public function testWrongNameIsRejected(): void
    {
        self::assertFalse($this->verifier()->matches($this->reservation('Müller'), $this->input('Maier')));
    }

    public function testNameOfALinkedGuestIsAccepted(): void
    {
        $reservation = $this->reservation('Müller');
        $companion = new Customer();
        $companion->setLastname('Novak');
        $reservation->addCustomer($companion);

        self::assertTrue($this->verifier()->matches($reservation, $this->input('Novak')));
    }

    public function testWrongDatesAreRejectedDespiteTheRightName(): void
    {
        $input = $this->input('Müller');
        $input->departure = new \DateTimeImmutable('2026-10-13');

        self::assertFalse($this->verifier()->matches($this->reservation('Müller'), $input));
    }

    public function testBookingWithoutNamesIsCheckedByDatesOnly(): void
    {
        $reservation = $this->reservation(null);

        self::assertFalse($this->verifier()->asksLastName($reservation));
        self::assertTrue($this->verifier()->matches($reservation, $this->input(null)));
    }

    public function testCookieProvesTheCheckForThisLinkOnly(): void
    {
        $verifier = $this->verifier();
        $checkIn = new GuestCheckIn(new Reservation(), 'selectorA');
        $cookie = $verifier->createCookie($checkIn, Request::create('https://example.com/checkin/x'), '/checkin/x');

        self::assertTrue($cookie->isSecure());
        self::assertTrue($verifier->isVerified(new Request(cookies: ['fhb_gci' => $cookie->getValue()]), $checkIn));
        self::assertFalse($verifier->isVerified(new Request(cookies: ['fhb_gci' => $cookie->getValue()]), new GuestCheckIn(new Reservation(), 'selectorB')));
        self::assertFalse($verifier->isVerified(new Request(), $checkIn));
    }

    private function verifier(): GuestCheckInVerifier
    {
        return new GuestCheckInVerifier(new GuestCheckInTokenSigner('secret'), new MockClock('2026-09-20 12:00'));
    }

    private function reservation(?string $bookerLastname): Reservation
    {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-10-10'));
        $reservation->setEndDate(new \DateTime('2026-10-12'));
        if (null !== $bookerLastname) {
            $booker = new Customer();
            $booker->setLastname($bookerLastname);
            $reservation->setBooker($booker);
        }

        return $reservation;
    }

    private function input(?string $lastname): GuestCheckInVerification
    {
        $input = new GuestCheckInVerification();
        $input->arrival = new \DateTimeImmutable('2026-10-10');
        $input->departure = new \DateTimeImmutable('2026-10-12');
        $input->lastname = $lastname;

        return $input;
    }
}
