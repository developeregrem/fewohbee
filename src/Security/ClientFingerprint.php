<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Rate-limiter key for anonymous visitors of public pages.
 *
 * IP plus user agent and language: behind a proxy that does not forward client addresses all
 * visitors share one IP, and the headers still keep them apart. Not an identity — a client can
 * change its headers — only a way to make a limiter hit one visitor rather than all of them.
 */
final class ClientFingerprint
{
    public static function limiterKey(Request $request, string $scope): string
    {
        $ip = (string) ($request->getClientIp() ?? 'unknown');
        $userAgent = mb_strtolower(trim((string) $request->headers->get('User-Agent', 'unknown')));
        $language = mb_strtolower(trim((string) $request->headers->get('Accept-Language', 'unknown')));

        return sprintf(
            '%s:%s',
            $scope,
            hash('sha256', $ip.'|'.$userAgent.'|'.$language)
        );
    }
}
