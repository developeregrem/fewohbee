<?php

declare(strict_types=1);

namespace App\Notification;

use App\Dto\NotificationItem;
use App\Entity\Enum\NotificationSeverity;
use App\Entity\User;

/**
 * A source of entries for the notification centre.
 *
 * Implementations are picked up automatically through the `app.notification.provider`
 * tag (see the `_instanceof` block in config/services.yaml) — adding a source means
 * adding a class, never editing a registry or a switch.
 *
 * Two kinds of provider exist side by side:
 *  - derived providers count live state (open conflicts, unconfirmed entries).
 *    Their entries disappear on their own once the underlying state is resolved,
 *    so they have no read state.
 *  - stored providers read persisted rows and track what a user has already seen.
 */
interface NotificationProviderInterface
{
    /** Stable identifier, e.g. "reservation_conflict". */
    public function getKey(): string;

    /** Role gate. Called before any counting happens. */
    public function isVisibleFor(User $user): bool;

    /**
     * Cheap count for the bell badge.
     *
     * Runs on every page render, so this must stay a COUNT query — never load
     * entities here.
     */
    public function countUnread(User $user): int;

    /**
     * How loud this provider currently is.
     *
     * Separate from getItems() because the badge needs the colour on every page
     * render; loading entities just to read a severity would defeat the point.
     */
    public function getSeverity(User $user): NotificationSeverity;

    /**
     * The entries themselves, newest/most urgent first.
     *
     * Only called when the user opens the panel, so this may do real work.
     *
     * @return NotificationItem[]
     */
    public function getItems(User $user, int $limit): array;
}
