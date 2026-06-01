<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\SmtpPasswordCrypto;
use PHPUnit\Framework\TestCase;

final class SmtpPasswordCryptoTest extends TestCase
{
    public function testEncryptAndDecryptRoundTrip(): void
    {
        $crypto = new SmtpPasswordCrypto('test-secret');

        $encrypted = $crypto->encrypt('smtp-pass');

        self::assertNotSame('smtp-pass', $encrypted);
        self::assertStringStartsWith('v1:', $encrypted);
        self::assertSame('smtp-pass', $crypto->decrypt($encrypted));
    }

    public function testDecryptReturnsNullForEmptyPassword(): void
    {
        $crypto = new SmtpPasswordCrypto('test-secret');

        self::assertNull($crypto->decrypt(null));
        self::assertNull($crypto->decrypt(''));
    }
}
