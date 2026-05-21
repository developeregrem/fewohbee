<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum PercentageBase: string
{
    case NET = 'net';
    case GROSS = 'gross';
}
