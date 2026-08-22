<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum GuestStatisticalGroup: string
{
    case ADULT = 'adult';
    case CHILD = 'child';
    case INFANT = 'infant';
    case OTHER = 'other';

    /**
     * FontAwesome icon shown next to a guest in the public booking wizard.
     *
     * Only guests that do not occupy a bed are rendered this way, so the guest
     * can tell an infant in a cot from someone taking one of the beds.
     */
    public function publicIcon(): string
    {
        return match ($this) {
            self::INFANT => 'fa-baby',
            self::CHILD => 'fa-child',
            default => 'fa-user-tag',
        };
    }

    public function otaCode(): ?string
    {
        return match ($this) {
            self::ADULT => 'Adult',
            self::CHILD => 'Child',
            self::INFANT => 'Infant',
            default => null,
        };
    }
}
