<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Mcp\Security\PreviewTokenSigner;
use PHPUnit\Framework\TestCase;

final class PreviewTokenSignerTest extends TestCase
{
    private const FINGERPRINT = 'a1b2c3';

    public function testAcceptsTokenForSameAccessTokenAndRequest(): void
    {
        $signer = new PreviewTokenSigner('secret');
        $token = $signer->sign(7, self::FINGERPRINT, 1_000_000);

        self::assertTrue($signer->verify($token, 7, self::FINGERPRINT, 1_000_000 + 60));
    }

    public function testRejectsExpiredToken(): void
    {
        $signer = new PreviewTokenSigner('secret');
        $token = $signer->sign(7, self::FINGERPRINT, 1_000_000);

        self::assertFalse($signer->verify($token, 7, self::FINGERPRINT, 1_000_000 + PreviewTokenSigner::TTL_SECONDS + 1));
    }

    public function testRejectsOtherAccessToken(): void
    {
        $signer = new PreviewTokenSigner('secret');
        $token = $signer->sign(7, self::FINGERPRINT, 1_000_000);

        self::assertFalse($signer->verify($token, 8, self::FINGERPRINT, 1_000_000));
    }

    public function testRejectsChangedRequest(): void
    {
        $signer = new PreviewTokenSigner('secret');
        $token = $signer->sign(7, self::FINGERPRINT, 1_000_000);

        self::assertFalse($signer->verify($token, 7, 'other', 1_000_000));
    }

    public function testRejectsTokenSignedWithOtherSecret(): void
    {
        $token = (new PreviewTokenSigner('other-secret'))->sign(7, self::FINGERPRINT, 1_000_000);

        self::assertFalse((new PreviewTokenSigner('secret'))->verify($token, 7, self::FINGERPRINT, 1_000_000));
    }

    public function testRejectsForgedExpiry(): void
    {
        $signer = new PreviewTokenSigner('secret');
        [$version, , $mac] = explode('.', $signer->sign(7, self::FINGERPRINT, 1_000_000));

        self::assertFalse($signer->verify($version.'.9999999999.'.$mac, 7, self::FINGERPRINT, 1_000_000));
    }

    public function testRejectsMalformedToken(): void
    {
        $signer = new PreviewTokenSigner('secret');

        self::assertFalse($signer->verify('garbage', 7, self::FINGERPRINT));
        self::assertFalse($signer->verify('pv1.abc.def', 7, self::FINGERPRINT));
    }
}
