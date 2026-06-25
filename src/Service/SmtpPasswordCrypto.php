<?php

declare(strict_types=1);

namespace App\Service;

final class SmtpPasswordCrypto
{
    private const PREFIX = 'v1:';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private readonly string $key;

    public function __construct(string $appSecret)
    {
        $this->key = hash('sha256', $appSecret, true);
    }

    public function encrypt(string $plainText): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $cipherText = openssl_encrypt(
            $plainText,
            'aes-256-gcm',
            $this->key,
            \OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if (false === $cipherText) {
            throw new \RuntimeException('Could not encrypt SMTP password.');
        }

        return self::PREFIX.base64_encode($iv.$tag.$cipherText);
    }

    public function decrypt(?string $encrypted): ?string
    {
        if (null === $encrypted || '' === trim($encrypted)) {
            return null;
        }

        if (!str_starts_with($encrypted, self::PREFIX)) {
            throw new \RuntimeException('Unsupported SMTP password format.');
        }

        $payload = base64_decode(substr($encrypted, \strlen(self::PREFIX)), true);
        if (false === $payload || \strlen($payload) <= self::IV_LENGTH + self::TAG_LENGTH) {
            throw new \RuntimeException('Invalid SMTP password payload.');
        }

        $iv = substr($payload, 0, self::IV_LENGTH);
        $tag = substr($payload, self::IV_LENGTH, self::TAG_LENGTH);
        $cipherText = substr($payload, self::IV_LENGTH + self::TAG_LENGTH);
        $plainText = openssl_decrypt(
            $cipherText,
            'aes-256-gcm',
            $this->key,
            \OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if (false === $plainText) {
            throw new \RuntimeException('Could not decrypt SMTP password.');
        }

        return $plainText;
    }
}
