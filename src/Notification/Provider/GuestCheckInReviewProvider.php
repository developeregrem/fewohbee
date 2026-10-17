<?php

declare(strict_types=1);

namespace App\Notification\Provider;

use App\Dto\NotificationItem;
use App\Entity\Enum\NotificationSeverity;
use App\Entity\GuestCheckIn;
use App\Entity\User;
use App\Notification\NotificationProviderInterface;
use App\Repository\GuestCheckInRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Online check-ins waiting for staff review, one entry each, opening the reservation's
 * check-in tab.
 *
 * A derived provider: an entry disappears once the check-in is confirmed or discarded, so there
 * is nothing to mark as read.
 */
final class GuestCheckInReviewProvider implements NotificationProviderInterface
{
    private ?int $count = null;

    public function __construct(
        private readonly GuestCheckInRepository $repository,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getKey(): string
    {
        return 'guest_checkin_review';
    }

    public function isVisibleFor(User $user): bool
    {
        // Confirming is a write, so read-only staff cannot act on these.
        return $this->security->isGranted('ROLE_RESERVATIONS');
    }

    public function countUnread(User $user): int
    {
        // Memoised: the badge asks for count and severity on every page render.
        return $this->count ??= $this->repository->countAwaitingReview();
    }

    public function getSeverity(User $user): NotificationSeverity
    {
        return NotificationSeverity::INFO;
    }

    public function getItems(User $user, int $limit): array
    {
        if ($this->countUnread($user) < 1) {
            return [];
        }

        return array_map(fn (GuestCheckIn $checkIn): NotificationItem => new NotificationItem(
            key: $this->getKey(),
            severity: NotificationSeverity::INFO,
            icon: 'fa-id-card',
            titleKey: 'notification.guest_checkin_review.title',
            titleParams: ['%name%' => self::guestName($checkIn)],
            bodyKey: 'notification.guest_checkin_review.body',
            bodyParams: [
                '%room%' => (string) $checkIn->getReservation()->getAppartment()?->getNumber(),
                '%from%' => $checkIn->getReservation()->getStartDate()->format('d.m.Y'),
            ],
            createdAt: $checkIn->getLastSubmittedAt(),
            modalUrl: $this->urlGenerator->generate('reservations.get.reservation', ['id' => $checkIn->getReservation()->getId(), 'tab' => 'checkin']),
            modalTitle: 'reservation.details',
        ), $this->repository->findAwaitingReview($limit));
    }

    /** Who checked in, as the guest entered it; the booker or the reservation number otherwise. */
    private static function guestName(GuestCheckIn $checkIn): string
    {
        $main = $checkIn->getPayload()['mainGuest'] ?? [];
        $name = \is_array($main) ? trim(($main['firstname'] ?? '').' '.($main['lastname'] ?? '')) : '';
        $booker = $checkIn->getReservation()->getBooker();
        if ('' === $name && null !== $booker) {
            $name = trim($booker->getFirstname().' '.$booker->getLastname());
        }

        return '' !== $name ? $name : '#'.$checkIn->getReservation()->getId();
    }
}
