<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/** How a price changed by price rules is rounded; unchanged prices are never rounded. */
enum PriceRounding: string
{
    case CENT = 'cent';
    case EURO = 'euro';

    public function round(float $amount): float
    {
        return match ($this) {
            self::CENT => round($amount, 2),
            self::EURO => round($amount),
        };
    }
}
