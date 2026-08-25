<?php

declare(strict_types=1);

namespace App\Notification\Provider;

use App\Dto\NotificationItem;
use App\Entity\Enum\NotificationSeverity;
use App\Entity\User;
use App\Notification\NotificationProviderInterface;
use App\Service\ReleaseNotesService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "What's new" after an update, for as long as the user has not seen it.
 *
 * Visible to every role: a receptionist benefits from knowing what changed in
 * the screen they work in all day, not just the administrator.
 */
final class ReleaseNoteProvider implements NotificationProviderInterface
{
    public function __construct(
        private readonly ReleaseNotesService $releaseNotes,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getKey(): string
    {
        return 'release_note';
    }

    public function isVisibleFor(User $user): bool
    {
        return true;
    }

    public function countUnread(User $user): int
    {
        return $this->hasUnseenRelease($user) ? 1 : 0;
    }

    public function getSeverity(User $user): NotificationSeverity
    {
        return NotificationSeverity::INFO;
    }

    public function getItems(User $user, int $limit): array
    {
        if (!$this->hasUnseenRelease($user)) {
            return [];
        }

        $version = $this->releaseNotes->getCurrentVersion();

        return [new NotificationItem(
            key: $this->getKey(),
            severity: NotificationSeverity::INFO,
            icon: 'fa-gift',
            titleKey: 'notification.release_note.title',
            titleParams: ['%version%' => $version],
            bodyKey: 'notification.release_note.body',
            modalUrl: $this->urlGenerator->generate('release_notes.modal', ['version' => $version]),
            modalTitle: 'release_notes.announce.title',
        )];
    }

    private function hasUnseenRelease(User $user): bool
    {
        $current = $this->releaseNotes->getCurrentVersion();

        return $user->getLastSeenVersion() !== $current && $this->releaseNotes->hasNotesFor($current);
    }
}
