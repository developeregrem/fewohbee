<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * How a group of fields appears on the online check-in form.
 *
 * Registration duties differ by country, so the hotelier decides per group what is asked.
 */
enum GuestCheckInFieldMode: string
{
    case HIDDEN = 'hidden';
    case OPTIONAL = 'optional';
    case REQUIRED = 'required';

    public function isShown(): bool
    {
        return self::HIDDEN !== $this;
    }

    public function labelKey(): string
    {
        return 'guest_checkin.field_mode.'.$this->value;
    }
}
