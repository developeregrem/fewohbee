<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * How loudly a notification asks for attention.
 *
 * The bell badge turns red only for Critical. Colouring warnings red too would
 * blunt the signal and let real conflicts disappear among routine reminders.
 */
enum NotificationSeverity: string
{
    case CRITICAL = 'critical';
    case WARNING = 'warning';
    case INFO = 'info';

    /** Higher wins when the bell picks one colour for a mixed list. */
    public function weight(): int
    {
        return match ($this) {
            self::CRITICAL => 3,
            self::WARNING => 2,
            self::INFO => 1,
        };
    }

    /** Bootstrap contextual class for the badge and the list marker. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::CRITICAL => 'bg-danger',
            self::WARNING => 'bg-warning',
            self::INFO => 'bg-primary',
        };
    }

    public function textClass(): string
    {
        return match ($this) {
            self::CRITICAL => 'text-danger',
            self::WARNING => 'text-warning',
            self::INFO => 'text-primary',
        };
    }
}
