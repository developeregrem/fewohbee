<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Enum\NotificationSeverity;

/**
 * What the bell needs to render: how many, and how loud.
 *
 * Deliberately does not carry the items — the badge is rendered on every page,
 * the list only when the dropdown is opened.
 */
final readonly class NotificationSummary
{
    public function __construct(
        public int $total,
        public ?NotificationSeverity $severity,
    ) {
    }

    public function isEmpty(): bool
    {
        return 0 === $this->total;
    }

    public function badgeClass(): string
    {
        return $this->severity?->badgeClass() ?? 'bg-secondary';
    }
}
