<?php

declare(strict_types=1);

namespace App\Service\Oidc;

/**
 * How this client authenticates at the token endpoint. Only the two shared
 * secret methods are implemented; anything else has to be reported rather than
 * silently replaced by a guess.
 */
enum OidcClientAuthMethod: string
{
    case Basic = 'client_secret_basic';
    case Post = 'client_secret_post';
}
