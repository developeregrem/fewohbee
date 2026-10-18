<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * How a price changed by price rules is rounded; unchanged prices are never rounded. Independent
 * of the currency: "whole" means whole amounts of whichever currency is configured.
 */
enum PriceRounding: string
{
    /** Kept to the smallest unit of the currency (two decimals). */
    case NONE = 'none';
    case WHOLE = 'whole';

    public function round(float $amount): float
    {
        return match ($this) {
            self::NONE => round($amount, 2),
            self::WHOLE => round($amount),
        };
    }
}
