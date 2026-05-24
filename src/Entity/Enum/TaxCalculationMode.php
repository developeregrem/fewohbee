<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum TaxCalculationMode: string
{
    case PER_NIGHT_FLAT = 'per_night_flat';
    case PERCENT_PER_ROOM = 'percent_per_room';

    public function isPercentage(): bool
    {
        return $this === self::PERCENT_PER_ROOM;
    }
}
