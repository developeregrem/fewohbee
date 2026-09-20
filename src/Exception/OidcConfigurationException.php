<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when single sign-on is switched on but the .env configuration is
 * incomplete or the identity provider's discovery document does not match it.
 *
 * This is an operator error, not an end-user error: it is logged and surfaced
 * as a generic login failure so nothing about the setup leaks to the browser.
 */
class OidcConfigurationException extends \RuntimeException
{
}
