<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\NotificationItem;
use App\Dto\NotificationSummary;
use App\Entity\Enum\NotificationSeverity;
use App\Entity\Notification;
use App\Entity\NotificationRead;
use App\Entity\User;
use App\Notification\NotificationProviderRegistry;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;

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
        private readonly EntityManagerInterface $em,
        private readonly NotificationRepository $notificationRepository,
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

    /**
     * Records a notification for everyone who may see it.
     *
     * @param array<string, string|int> $params      placeholders for the title translation
     * @param array<string, string|int> $routeParams parameters for $routeName
     * @param string|null               $note        operator-written explanation, shown below the title
     */
    public function create(
        string $type,
        string $titleKey,
        NotificationSeverity $severity = NotificationSeverity::INFO,
        array $params = [],
        ?string $routeName = null,
        array $routeParams = [],
        ?string $requiredRole = null,
        ?string $entityClass = null,
        ?string $entityId = null,
        ?string $note = null,
    ): Notification {
        $notification = (new Notification())
            ->setType($type)
            ->setTitleKey($titleKey)
            ->setSeverity($severity)
            ->setParams([] === $params ? null : $params)
            ->setRouteName($routeName)
            ->setRouteParams([] === $routeParams ? null : $routeParams)
            ->setRequiredRole($requiredRole)
            ->setEntityClass($entityClass)
            ->setEntityId($entityId)
            ->setNote('' === $note ? null : $note);

        $this->em->persist($notification);
        $this->summaryCache = [];

        return $notification;
    }

    /** Marks one notification as read. Reading twice is not an error. */
    public function markRead(User $user, int $notificationId): void
    {
        $notification = $this->notificationRepository->find($notificationId);
        if (null === $notification) {
            return;
        }

        $existing = $this->em->getRepository(NotificationRead::class)
            ->findOneBy(['notification' => $notification, 'user' => $user]);
        if (null !== $existing) {
            return;
        }

        $this->em->persist((new NotificationRead())->setNotification($notification)->setUser($user));
        $this->em->flush();
        $this->summaryCache = [];
    }

    /**
     * Marks everything currently visible to the user as read.
     *
     * @param string[] $roles the user's effective roles, including inherited ones
     */
    public function markAllRead(User $user, array $roles): void
    {
        foreach ($this->notificationRepository->findUnreadFor($user, $roles, 500) as $notification) {
            $this->em->persist((new NotificationRead())->setNotification($notification)->setUser($user));
        }

        $this->em->flush();
        $this->summaryCache = [];
    }
}
