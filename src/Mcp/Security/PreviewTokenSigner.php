<?php

declare(strict_types=1);

namespace App\Mcp\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stateless confirmation tokens for the preview → create booking flow.
 *
 * preview_reservation hands out a token that is an HMAC over the access token id, an expiry and
 * the fingerprint of the exact booking request. create_reservation only proceeds with a valid,
 * unexpired token for identical arguments, so the assistant must have looked at price and
 * availability first and cannot change the booking in between. Nothing is stored.
 */
class PreviewTokenSigner
{
    public const TTL_SECONDS = 600;
    private const VERSION = 'pv1';

    private readonly string $key;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        string $secret,
    ) {
        // Derived key: a leaked preview token must not help with any other use of the secret.
        $this->key = hash_hmac('sha256', 'fewohbee-mcp-preview-token', $secret, true);
    }

    public function sign(int $apiTokenId, string $fingerprint, ?int $now = null): string
    {
        $expiresAt = ($now ?? time()) + self::TTL_SECONDS;

        return self::VERSION.'.'.$expiresAt.'.'.$this->mac($apiTokenId, $expiresAt, $fingerprint);
    }

    public function verify(string $token, int $apiTokenId, string $fingerprint, ?int $now = null): bool
    {
        $parts = explode('.', $token);
        if (3 !== \count($parts) || self::VERSION !== $parts[0] || !ctype_digit($parts[1])) {
            return false;
        }

        $expiresAt = (int) $parts[1];
        if ($expiresAt < ($now ?? time())) {
            return false;
        }

        return hash_equals($this->mac($apiTokenId, $expiresAt, $fingerprint), $parts[2]);
    }

    public static function expiresAt(string $token): ?\DateTimeImmutable
    {
        $parts = explode('.', $token);

        return 3 === \count($parts) && ctype_digit($parts[1]) ? new \DateTimeImmutable('@'.$parts[1]) : null;
    }

    private function mac(int $apiTokenId, int $expiresAt, string $fingerprint): string
    {
        return hash_hmac('sha256', $apiTokenId.'|'.$expiresAt.'|'.$fingerprint, $this->key);
    }
}
