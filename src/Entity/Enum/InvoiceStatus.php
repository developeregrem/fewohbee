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

    /**
     * The status to show for a stored value; an unknown value shows as cancelled, like the
     * InvoiceStatus badge always did.
     */
    public static function forDisplay(?int $status): self
    {
        return self::fromStatus($status) ?? self::CANCELED;
    }

    /**
     * The statuses in the order a menu offers them: from open to settled, cancelled last.
     *
     * @return list<self>
     */
    public static function menuOrder(): array
    {
        return [self::OPEN, self::PREPAID, self::PAID, self::CANCELED];
    }

    /** Text colour of the status icon (InvoiceStatusPicker, reservation popover). */
    public function textClass(): string
    {
        return match ($this) {
            self::OPEN => 'text-warning',
            self::PREPAID => 'text-partial',
            self::PAID => 'text-success',
            self::CANCELED => 'text-body-secondary',
        };
    }

    /** Font Awesome icon at the corner of the status icon: the status as a shape, not only a colour. */
    public function markIcon(): string
    {
        return match ($this) {
            self::OPEN => 'fa-clock',
            self::PREPAID => 'fa-circle-half-stroke',
            self::PAID => 'fa-circle-check',
            self::CANCELED => 'fa-circle-xmark',
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
