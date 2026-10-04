<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Who set a day price. Set by hand it is taken exactly; proposed by an AI assistant or delivered
 * by another program it is held within the limits for price changes.
 */
enum DayPriceSource: string
{
    case MANUAL = 'manual';
    case ASSISTANT = 'assistant';
    case API = 'api';

    public function isLimited(): bool
    {
        return self::MANUAL !== $this;
    }
}
