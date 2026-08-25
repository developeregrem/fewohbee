<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\NotificationItem;
use App\Dto\NotificationSummary;
use App\Entity\User;
use App\Notification\NotificationProviderRegistry;

/**
 * Aggregates every notification provider into what the bell shows.
 *
 * The summary is memoised per request because base.html.twig renders the badge
 * on every page, and several templates may ask for it within one render.
 */
class NotificationCenterService
{
    /** @var array<int, NotificationSummary> keyed by user id */
    private array $summaryCache = [];

    public function __construct(
        private readonly NotificationProviderRegistry $registry,
    ) {
    }

    /** Counts only — used for the badge, so it must stay cheap. */
    public function getSummary(User $user): NotificationSummary
    {
        $cacheKey = (int) $user->getId();
        if (isset($this->summaryCache[$cacheKey])) {
            return $this->summaryCache[$cacheKey];
        }

        $total = 0;
        $severity = null;

        foreach ($this->registry->all() as $provider) {
            if (!$provider->isVisibleFor($user)) {
                continue;
            }

            $count = $provider->countUnread($user);
            if ($count < 1) {
                continue;
            }

            $total += $count;

            // The badge takes the loudest severity present, so a single conflict
            // still turns the bell red among a pile of routine reminders.
            $providerSeverity = $provider->getSeverity($user);
            if (null === $severity || $providerSeverity->weight() > $severity->weight()) {
                $severity = $providerSeverity;
            }
        }

        return $this->summaryCache[$cacheKey] = new NotificationSummary($total, $severity);
    }

    /**
     * The entries for the panel, grouped by provider key and ordered by severity.
     *
     * @return array<string, NotificationItem[]>
     */
    public function getGroupedItems(User $user, int $limitPerProvider = 5): array
    {
        $groups = [];

        foreach ($this->registry->all() as $key => $provider) {
            if (!$provider->isVisibleFor($user)) {
                continue;
            }

            $items = $provider->getItems($user, $limitPerProvider);
            if ([] === $items) {
                continue;
            }

            $groups[$key] = $items;
        }

        uasort($groups, static function (array $a, array $b): int {
            return ($b[0]->severity->weight() <=> $a[0]->severity->weight());
        });

        return $groups;
    }
}
