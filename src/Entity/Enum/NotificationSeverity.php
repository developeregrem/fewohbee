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

    /**
     * Badge classes for a light background, i.e. inside the notification panel.
     *
     * White on amber only reaches 2.2:1, so the warning badge carries dark text.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::CRITICAL => 'bg-danger text-white',
            self::WARNING => 'bg-warning text-dark',
            self::INFO => 'bg-primary text-white',
        };
    }

    /**
     * Badge classes for the primary-coloured navbar.
     *
     * The navbar is #2196f3, so the info badge cannot stay bg-primary — it would
     * be exactly the same colour (contrast 1.0, literally invisible); a light
     * pill separates cleanly instead. Red and amber measure low against that
     * blue too (1.5 and 1.45), but their hue carries them, and outlining them
     * turned the badge into a halo that shouted over the rest of the navbar.
     */
    public function badgeClassOnPrimary(): string
    {
        return match ($this) {
            self::CRITICAL => 'bg-danger text-white',
            self::WARNING => 'bg-warning text-dark',
            self::INFO => 'bg-light text-dark',
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
