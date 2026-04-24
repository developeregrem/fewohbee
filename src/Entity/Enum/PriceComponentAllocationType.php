<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * How a PriceComponent's share of the parent package price is expressed.
 */
enum PriceComponentAllocationType: string
{
    case PERCENT = 'percent';
    case AMOUNT = 'amount';
}
