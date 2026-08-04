<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiToken;

/**
 * Request-scoped holder for the ApiToken that authenticated the current request.
 * The security token only carries the User; scope checks and rate limiting need the token itself.
 */
class ApiTokenContext
{
    private ?ApiToken $token = null;

    public function setToken(ApiToken $token): void
    {
        $this->token = $token;
    }

    public function getToken(): ?ApiToken
    {
        return $this->token;
    }
}
