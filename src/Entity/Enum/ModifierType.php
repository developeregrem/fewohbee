<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ModifierType: string
{
    case SURCHARGE_ABSOLUTE = 'surcharge_absolute';
    case DISCOUNT_PERCENT = 'discount_percent';
    case FLAT_RATE = 'flat_rate';
    case FREE = 'free';
}
