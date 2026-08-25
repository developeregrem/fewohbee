<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Enum\NotificationSeverity;

/**
 * One entry in the notification centre.
 *
 * Titles are carried as translation key plus parameters rather than as finished
 * text, so the same item renders in German and English without storing both.
 *
 * A `modalUrl` makes the entry open the existing shared modal (#modalCenter)
 * instead of navigating — that is how conflicts and calendar reminders keep
 * their current screens.
 */
final readonly class NotificationItem
{
    /**
     * @param array<string, string|int> $titleParams
     * @param array<string, string|int> $bodyParams
     */
    public function __construct(
        public string $key,
        public NotificationSeverity $severity,
        public string $icon,
        public string $titleKey,
        public array $titleParams = [],
        public ?string $bodyKey = null,
        public array $bodyParams = [],
        public ?\DateTimeImmutable $createdAt = null,
        public ?string $url = null,
        public ?string $modalUrl = null,
        public ?string $modalTitle = null,
        public ?int $id = null,
        public int $count = 1,
    ) {
    }
}
