<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Invoice status mapping for templates.
 */
enum InvoiceStatus: int
{
    case OPEN = 1;
    case PAYED = 2;
    case PREPAYED = 3;
    case CANCELED = 4;

    public static function fromStatus(?int $status): ?self
    {
        if (null === $status) {
            return null;
        }

        return match ($status) {
            1 => self::OPEN,
            2 => self::PAYED,
            3 => self::PREPAYED,
            4 => self::CANCELED,
            default => null,
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::OPEN => 'invoice.status.notpayed',
            self::PAYED => 'invoice.status.payed',
            self::PREPAYED => 'invoice.status.prepayment',
            self::CANCELED => 'invoice.status.canceled',
        };
    }
}
