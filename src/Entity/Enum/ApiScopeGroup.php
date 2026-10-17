<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * The question a token scope answers in the token form and in the summary of a token: what the
 * access may see, which personal data it receives and what it may change.
 */
enum ApiScopeGroup: string
{
    case SEE = 'see';
    case PERSONAL_DATA = 'personal_data';
    case CHANGE = 'change';
}
