<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Invoice status mapping for templates and statistics.
 */
enum InvoiceStatus: int
{
    case OPEN = 1;
    case PAID = 2;
    case PREPAID = 3;
    case CANCELED = 4;

    public static function fromStatus(?int $status): ?self
    {
        if (null === $status) {
            return null;
        }

        return match ($status) {
            1 => self::OPEN,
            2 => self::PAID,
            3 => self::PREPAID,
            4 => self::CANCELED,
            default => null,
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::OPEN => 'invoice.status.unpaid',
            self::PAID => 'invoice.status.paid',
            self::PREPAID => 'invoice.status.prepaid',
            self::CANCELED => 'invoice.status.canceled',
        };
    }
}
