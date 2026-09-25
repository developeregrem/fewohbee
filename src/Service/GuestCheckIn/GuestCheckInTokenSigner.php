<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stateless secrets of the online check-in: the link token and the proof that a visitor passed
 * the booking-details check.
 *
 * Link token = selector (stored, random) + MAC over the selector (never stored). A database leak
 * alone therefore yields no working link, yet any link can be rebuilt at any time for templates,
 * and replacing the selector revokes it. Rotating APP_SECRET invalidates every link.
 */
class GuestCheckInTokenSigner
{
    /** 16 random bytes, base64url without padding. */
    public const SELECTOR_LENGTH = 22;

    /** Selector plus a 128-bit MAC, both base64url; also the route requirement. */
    public const TOKEN_LENGTH = 44;

    private const LINK_VERSION = 'gci1';
    private const VERIFICATION_VERSION = 'v1';

    private readonly string $linkKey;
    private readonly string $verificationKey;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        string $secret,
    ) {
        // Derived keys: neither token helps with the other or with any other use of the secret.
        $this->linkKey = hash_hmac('sha256', 'fewohbee-guest-checkin-link', $secret, true);
        $this->verificationKey = hash_hmac('sha256', 'fewohbee-guest-checkin-verified', $secret, true);
    }

    public function newSelector(): string
    {
        return self::base64Url(random_bytes(16));
    }

    public function linkToken(string $selector): string
    {
        return $selector.$this->linkMac($selector);
    }

    /**
     * Selector of a well-formed, authentic token, or null. Checked before any database access,
     * so guessing tokens never reaches a query.
     */
    public function selectorFromLinkToken(string $token): ?string
    {
        if (self::TOKEN_LENGTH !== \strlen($token) || 1 !== preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            return null;
        }

        $selector = substr($token, 0, self::SELECTOR_LENGTH);

        return hash_equals($this->linkMac($selector), substr($token, self::SELECTOR_LENGTH)) ? $selector : null;
    }

    /** Cookie value proving the booking details were entered for this selector until $expiresAt. */
    public function verificationValue(string $selector, int $expiresAt): string
    {
        return self::VERIFICATION_VERSION.'.'.$expiresAt.'.'.$this->verificationMac($selector, $expiresAt);
    }

    public function isVerificationValid(string $value, string $selector, int $now): bool
    {
        $parts = explode('.', $value);
        if (3 !== \count($parts) || self::VERIFICATION_VERSION !== $parts[0] || !ctype_digit($parts[1])) {
            return false;
        }

        $expiresAt = (int) $parts[1];
        if ($expiresAt < $now) {
            return false;
        }

        return hash_equals($this->verificationMac($selector, $expiresAt), $parts[2]);
    }

    private function linkMac(string $selector): string
    {
        return self::base64Url(substr(hash_hmac('sha256', self::LINK_VERSION.'|'.$selector, $this->linkKey, true), 0, 16));
    }

    private function verificationMac(string $selector, int $expiresAt): string
    {
        return hash_hmac('sha256', $selector.'|'.$expiresAt, $this->verificationKey);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
