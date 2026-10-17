<?php

declare(strict_types=1);

namespace App\Notification\Provider;

use App\Dto\NotificationItem;
use App\Entity\Enum\NotificationSeverity;
use App\Entity\User;
use App\Notification\NotificationProviderInterface;
use App\Repository\ReceiptProposalRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Receipts an AI assistant handed in that wait for someone to book them.
 *
 * Derived: the entry disappears once every proposal is booked or discarded. The booking journal
 * shows the same count on its receipt button; this makes it visible from every screen.
 */
final class ReceiptProposalProvider implements NotificationProviderInterface
{
    private ?int $open = null;

    public function __construct(
        private readonly ReceiptProposalRepository $repository,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getKey(): string
    {
        return 'receipt_proposal';
    }

    public function isVisibleFor(User $user): bool
    {
        return $this->security->isGranted('ROLE_CASHJOURNAL');
    }

    public function countUnread(User $user): int
    {
        // Memoised: the badge and the panel ask several times per page view.
        return $this->open ??= $this->repository->countOpen();
    }

    public function getSeverity(User $user): NotificationSeverity
    {
        return NotificationSeverity::INFO;
    }

    public function getItems(User $user, int $limit): array
    {
        $open = $this->countUnread($user);
        if ($open < 1) {
            return [];
        }

        return [new NotificationItem(
            key: $this->getKey(),
            severity: NotificationSeverity::INFO,
            icon: 'fa-receipt',
            titleKey: 'notification.receipt_proposal.title',
            titleParams: ['%count%' => $open],
            bodyKey: 'notification.receipt_proposal.body',
            url: $this->urlGenerator->generate('journal.receipts.index'),
            count: $open,
        )];
    }
}
