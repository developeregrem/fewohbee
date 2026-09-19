<?php

declare(strict_types=1);

namespace App\Notification\Provider;

use App\Dto\NotificationItem;
use App\Entity\Enum\NotificationSeverity;
use App\Entity\User;
use App\Notification\NotificationProviderInterface;
use App\Repository\CalendarEntryRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Calendar entries due today that still need confirming.
 *
 * Visible to read-only staff on purpose: they should see what is outstanding
 * even though ticking it off requires ROLE_RESERVATIONS. That matches how the
 * button behaved in the reservation overview before it moved into the bell.
 */
final class CalendarReminderProvider implements NotificationProviderInterface
{
    public function __construct(
        private readonly CalendarEntryRepository $calendarEntryRepository,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getKey(): string
    {
        return 'calendar_reminder';
    }

    public function isVisibleFor(User $user): bool
    {
        return $this->security->isGranted('ROLE_RESERVATIONS_RO');
    }

    public function countUnread(User $user): int
    {
        return $this->calendarEntryRepository->countPendingReminders();
    }

    public function getSeverity(User $user): NotificationSeverity
    {
        return NotificationSeverity::WARNING;
    }

    public function getItems(User $user, int $limit): array
    {
        $total = $this->countUnread($user);
        if ($total < 1) {
            return [];
        }

        return [new NotificationItem(
            key: $this->getKey(),
            severity: NotificationSeverity::WARNING,
            icon: 'fa-calendar-check',
            titleKey: 'notification.calendar_reminder.title',
            titleParams: ['%count%' => $total],
            bodyKey: 'notification.calendar_reminder.body',
            modalUrl: $this->urlGenerator->generate('reservations.calendar_reminder'),
            modalTitle: 'reservation.calendar_reminder.title',
            count: $total,
        )];
    }
}
