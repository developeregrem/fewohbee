<?php

declare(strict_types=1);

namespace App\Twig;

use App\Dto\NotificationSummary;
use App\Entity\User;
use App\Service\NotificationCenterService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the notification bell's badge state to base.html.twig.
 *
 * Counts only — the panel itself is fetched when the dropdown opens, so a normal
 * page render costs one cheap COUNT per visible provider and nothing more.
 */
final class NotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly NotificationCenterService $notificationCenter,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('notification_summary', [$this, 'notificationSummary']),
        ];
    }

    public function notificationSummary(): NotificationSummary
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new NotificationSummary(0, null);
        }

        return $this->notificationCenter->getSummary($user);
    }
}
