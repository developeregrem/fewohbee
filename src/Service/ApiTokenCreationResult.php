<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;

/**
 * The plain token exists only in this result; it is never stored.
 */
final readonly class ApiTokenCreationResult
{
    public function __construct(
        public string $plainToken,
        public ApiToken $token,
    ) {
    }
}
