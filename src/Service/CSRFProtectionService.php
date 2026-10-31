<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Session-backed CSRF tokens for legacy AJAX forms.
 */
class CSRFProtectionService
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /**
     * Replace the shared session token and return its value for the legacy hidden form field.
     */
    public function getCSRFTokenForForm(): string
    {
        $token = $this->generateToken(20);
        $this->requestStack->getSession()->set('_csrf_token', $token);

        return $token;
    }

    /**
     * Accept only matching, non-empty string tokens.
     *
     * @param bool $invalidateToken Replace the shared token after validation, including rejected attempts.
     */
    public function validateCSRFToken(Request $request, bool $invalidateToken = false, string $fieldName = '_csrf_token'): bool
    {
        $savedToken = $this->requestStack->getSession()->get('_csrf_token');
        $submittedToken = $request->request->get($fieldName);
        $result = is_string($savedToken) && '' !== $savedToken
            && is_string($submittedToken) && '' !== $submittedToken
            && hash_equals($savedToken, $submittedToken);

        if ($invalidateToken) {
            $this->getCSRFTokenForForm();
        }

        return $result;
    }

    /**
     * Generate a cryptographically secure token in the legacy character set.
     */
    private function generateToken(int $length, bool $alphaNumeric = true): string
    {
        $numeric = '0123456789';
        $alpha = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $token = '';

        if ($alphaNumeric) {
            $chars = $numeric.$alpha;
        } else {
            $chars = $alpha;
        }

        $lastIndex = strlen($chars) - 1;
        for ($i = 0; $i < $length; ++$i) {
            $token .= $chars[random_int(0, $lastIndex)];
        }

        return $token;
    }
}
