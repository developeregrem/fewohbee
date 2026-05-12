<?php

declare(strict_types=1);

namespace App\Payment\Enum;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case INITIATED = 'initiated';
    case SETTLED = 'settled';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::SETTLED, self::FAILED, self::CANCELLED, self::REFUNDED => true,
            self::PENDING, self::INITIATED => false,
        };
    }
}
