<?php

declare(strict_types=1);

namespace App\Notification\Provider;

use App\Dto\NotificationItem;
use App\Entity\Enum\NotificationSeverity;
use App\Entity\User;
use App\Notification\NotificationProviderInterface;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Notifications recorded in the database, e.g. by the workflow engine.
 *
 * The counterpart to the derived providers: these are point-in-time events that
 * never resolve themselves, so each one is tracked per user until read.
 */
final class StoredNotificationProvider implements NotificationProviderInterface
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly Security $security,
        private readonly RoleHierarchyInterface $roleHierarchy,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getKey(): string
    {
        return 'stored';
    }

    public function isVisibleFor(User $user): bool
    {
        // Row-level visibility is decided by required_role in the query below.
        return true;
    }

    public function countUnread(User $user): int
    {
        return $this->notificationRepository->countUnreadFor($user, $this->reachableRoles());
    }

    public function getSeverity(User $user): NotificationSeverity
    {
        $items = $this->getItems($user, 50);

        $severity = NotificationSeverity::INFO;
        foreach ($items as $item) {
            if ($item->severity->weight() > $severity->weight()) {
                $severity = $item->severity;
            }
        }

        return $severity;
    }

    public function getItems(User $user, int $limit): array
    {
        $items = [];

        foreach ($this->notificationRepository->findUnreadFor($user, $this->reachableRoles(), $limit) as $notification) {
            $url = null;
            if (null !== $notification->getRouteName()) {
                $url = $this->urlGenerator->generate($notification->getRouteName(), $notification->getRouteParams());
            }

            $items[] = new NotificationItem(
                key: $this->getKey(),
                severity: $notification->getSeverity(),
                icon: 'fa-bell',
                titleKey: $notification->getTitleKey(),
                titleParams: $notification->getParams(),
                body: $notification->getNote(),
                createdAt: $notification->getCreatedAt(),
                url: $url,
                id: $notification->getId(),
            );
        }

        return $items;
    }

    /**
     * The user's roles including everything they inherit.
     *
     * The token only carries the roles assigned directly; role_hierarchy is
     * normally applied at access-check time, which a COUNT query cannot use.
     *
     * @return string[]
     */
    private function reachableRoles(): array
    {
        $token = $this->security->getToken();
        if (null === $token) {
            return [];
        }

        return $this->roleHierarchy->getReachableRoleNames($token->getRoleNames());
    }
}
