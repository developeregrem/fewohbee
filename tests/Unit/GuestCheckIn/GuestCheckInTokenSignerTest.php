<?php

declare(strict_types=1);

namespace App\Tests\Unit\GuestCheckIn;

use App\Service\GuestCheckIn\GuestCheckInTokenSigner;
use PHPUnit\Framework\TestCase;

final class GuestCheckInTokenSignerTest extends TestCase
{
    public function testTokenResolvesToItsSelector(): void
    {
        $signer = new GuestCheckInTokenSigner('secret');
        $selector = $signer->newSelector();

        $token = $signer->linkToken($selector);

        self::assertSame(GuestCheckInTokenSigner::SELECTOR_LENGTH, \strlen($selector));
        self::assertSame(GuestCheckInTokenSigner::TOKEN_LENGTH, \strlen($token));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
        self::assertSame($selector, $signer->selectorFromLinkToken($token));
    }

    public function testTamperedMacIsRejected(): void
    {
        $signer = new GuestCheckInTokenSigner('secret');
        $token = $signer->linkToken($signer->newSelector());
        $last = $token[-1];

        self::assertNull($signer->selectorFromLinkToken(substr($token, 0, -1).('A' === $last ? 'B' : 'A')));
    }

    public function testTokenOfAnotherSecretIsRejected(): void
    {
        $token = (new GuestCheckInTokenSigner('other'))->linkToken('AAAAAAAAAAAAAAAAAAAAAA');

        self::assertNull((new GuestCheckInTokenSigner('secret'))->selectorFromLinkToken($token));
    }

    public function testMalformedTokensAreRejected(): void
    {
        $signer = new GuestCheckInTokenSigner('secret');
        $token = $signer->linkToken($signer->newSelector());

        self::assertNull($signer->selectorFromLinkToken(substr($token, 0, -1)));
        self::assertNull($signer->selectorFromLinkToken($token.'A'));
        self::assertNull($signer->selectorFromLinkToken(str_repeat('+', GuestCheckInTokenSigner::TOKEN_LENGTH)));
        self::assertNull($signer->selectorFromLinkToken(''));
    }

    public function testVerificationValueIsBoundToSelectorAndExpiry(): void
    {
        $signer = new GuestCheckInTokenSigner('secret');
        $value = $signer->verificationValue('selectorA', 2000);

        self::assertTrue($signer->isVerificationValid($value, 'selectorA', 1999));
        self::assertTrue($signer->isVerificationValid($value, 'selectorA', 2000));
        self::assertFalse($signer->isVerificationValid($value, 'selectorA', 2001), 'expired');
        self::assertFalse($signer->isVerificationValid($value, 'selectorB', 1999), 'other link');
        self::assertFalse($signer->isVerificationValid(str_replace('.2000.', '.9999.', $value), 'selectorA', 1999), 'extended expiry');
        self::assertFalse($signer->isVerificationValid('garbage', 'selectorA', 1999));
    }

    public function testLinkAndVerificationUseDifferentKeys(): void
    {
        $signer = new GuestCheckInTokenSigner('secret');
        $selector = $signer->newSelector();

        self::assertStringNotContainsString(substr($signer->linkToken($selector), GuestCheckInTokenSigner::SELECTOR_LENGTH), $signer->verificationValue($selector, 2000));
    }
}
