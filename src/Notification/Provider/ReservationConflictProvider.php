<?php

declare(strict_types=1);

namespace App\Notification\Provider;

use App\Dto\NotificationItem;
use App\Entity\Enum\NotificationSeverity;
use App\Entity\User;
use App\Notification\NotificationProviderInterface;
use App\Repository\ReservationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Imported bookings that need a human look: real double bookings, and imports
 * that arrived without a booker.
 *
 * A derived provider — the entry disappears the moment the conflict is resolved
 * or ignored, so there is nothing to mark as read. The reservation overview keeps
 * its own red button for the same data; this makes it reachable from every screen.
 */
final class ReservationConflictProvider implements NotificationProviderInterface
{
    private ?int $conflicts = null;
    private ?int $reviews = null;

    public function __construct(
        private readonly ReservationRepository $reservationRepository,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getKey(): string
    {
        return 'reservation_conflict';
    }

    public function isVisibleFor(User $user): bool
    {
        // Resolving a conflict is a write, so read-only staff cannot act on these.
        return $this->security->isGranted('ROLE_RESERVATIONS');
    }

    public function countUnread(User $user): int
    {
        return $this->countConflicts() + $this->countReviews();
    }

    public function getSeverity(User $user): NotificationSeverity
    {
        // A real double booking is critical; an import missing its booker is
        // only paperwork, so it must not paint the bell red.
        return $this->countConflicts() > 0 ? NotificationSeverity::CRITICAL : NotificationSeverity::WARNING;
    }

    public function getItems(User $user, int $limit): array
    {
        $total = $this->countUnread($user);
        if ($total < 1) {
            return [];
        }

        return [new NotificationItem(
            key: $this->getKey(),
            severity: $this->getSeverity($user),
            icon: 'fa-triangle-exclamation',
            titleKey: 'notification.reservation_conflict.title',
            titleParams: ['%count%' => $total],
            bodyKey: $this->countConflicts() > 0
                ? 'notification.reservation_conflict.body_conflicts'
                : 'notification.reservation_conflict.body_reviews',
            modalUrl: $this->urlGenerator->generate('reservations.conflicts'),
            modalTitle: 'reservation.conflict.title',
            count: $total,
        )];
    }

    /**
     * Both counts are memoised for the request: the badge asks for the count and
     * the severity on every page render, and the panel asks again on top of that.
     * Without this, one page view would fire the same COUNT three times.
     */
    private function countConflicts(): int
    {
        return $this->conflicts ??= $this->reservationRepository->countActiveConflicts();
    }

    private function countReviews(): int
    {
        return $this->reviews ??= $this->reservationRepository->countImportedWithoutBooker();
    }
}
