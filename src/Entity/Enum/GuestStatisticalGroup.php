<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum GuestStatisticalGroup: string
{
    case ADULT = 'adult';
    case CHILD = 'child';
    case INFANT = 'infant';
    case OTHER = 'other';

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
