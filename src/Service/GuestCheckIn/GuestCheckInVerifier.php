<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use App\Dto\GuestCheckIn\GuestCheckInVerification;
use App\Entity\Customer;
use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Second factor of the public check-in page: whoever holds the link must also know the booking.
 *
 * A forwarded mail, a screenshot in a chat or a printed QR code lying around must not be enough
 * to see or change booking data. The visitor confirms arrival, departure and — when the booking
 * carries one — a last name; passing the check sets a signed cookie bound to the link for a
 * short while. Nothing is stored on the server.
 */
class GuestCheckInVerifier
{
    public const COOKIE_NAME = 'fhb_gci';
    public const TTL_SECONDS = 7200;

    public function __construct(
        private readonly GuestCheckInTokenSigner $signer,
        private readonly ClockInterface $clock,
    ) {
    }

    /** False for bookings without any name yet (e.g. fresh portal imports): only the dates count. */
    public function asksLastName(Reservation $reservation): bool
    {
        return [] !== $this->knownLastNames($reservation);
    }

    /**
     * Dates must match exactly; the name may be the booker's or any linked guest's, since a fellow
     * traveller may open a forwarded link. Differences in case, accents, hyphens and German
     * umlaut spelling (Müller/Mueller) are ignored.
     */
    public function matches(Reservation $reservation, GuestCheckInVerification $input): bool
    {
        $datesMatch = $input->arrival?->format('Y-m-d') === $reservation->getStartDate()->format('Y-m-d')
            && $input->departure?->format('Y-m-d') === $reservation->getEndDate()->format('Y-m-d');

        $known = $this->knownLastNames($reservation);
        if ([] === $known) {
            return $datesMatch;
        }

        $nameMatches = false;
        foreach (self::nameVariants((string) $input->lastname) as $candidate) {
            foreach ($known as $name) {
                // No early exit: every comparison runs, whatever matched.
                $nameMatches = hash_equals($name, $candidate) || $nameMatches;
            }
        }

        return $datesMatch && $nameMatches;
    }

    public function isVerified(Request $request, GuestCheckIn $checkIn): bool
    {
        $value = $request->cookies->get(self::COOKIE_NAME);

        return \is_string($value) && $this->signer->isVerificationValid($value, $checkIn->getSelector(), $this->clock->now()->getTimestamp());
    }

    /** Proof of a passed check, scoped to exactly this link's path. */
    public function createCookie(GuestCheckIn $checkIn, Request $request, string $path): Cookie
    {
        $expiresAt = $this->clock->now()->getTimestamp() + self::TTL_SECONDS;

        return Cookie::create(self::COOKIE_NAME)
            ->withValue($this->signer->verificationValue($checkIn->getSelector(), $expiresAt))
            ->withExpires($expiresAt)
            ->withPath($path)
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }

    /**
     * Comparable forms of a name: lower case, separators collapsed, once with accents stripped
     * (ü → u) and once with German transliteration (ü → ue).
     *
     * @return list<string>
     */
    public static function nameVariants(string $name): array
    {
        $name = trim((string) preg_replace("/[\\s\\-'’`´.]+/u", ' ', mb_strtolower(trim($name))));
        if ('' === $name) {
            return [];
        }

        $name = str_replace('ß', 'ss', $name);
        $german = strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue']);

        return array_values(array_unique([self::stripAccents($name), self::stripAccents($german)]));
    }

    /** @return list<string> */
    private function knownLastNames(Reservation $reservation): array
    {
        $customers = [$reservation->getBooker(), ...$reservation->getCustomers()];
        $names = [];
        foreach ($customers as $customer) {
            if ($customer instanceof Customer) {
                array_push($names, ...self::nameVariants((string) $customer->getLastname()));
            }
        }

        return array_values(array_unique($names));
    }

    private static function stripAccents(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if (\is_string($decomposed)) {
                $value = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        }

        return $value;
    }
}
