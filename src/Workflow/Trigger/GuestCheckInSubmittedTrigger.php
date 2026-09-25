<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use App\Service\GuestCheckIn\GuestCheckInConfigService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fires when a guest sent the online check-in form. Only offered while the online check-in is
 * switched on.
 *
 * Like every workflow, it runs at most once per reservation: later corrections by the guest do
 * not fire it again, they show up in the reservation's check-in tab.
 */
class GuestCheckInSubmittedTrigger implements ConditionalWorkflowTriggerInterface
{
    public const TYPE = 'guest_checkin.submitted';

    public function __construct(
        private readonly GuestCheckInConfigService $configService,
    ) {
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getLabelKey(): string
    {
        return 'workflow.trigger.guest_checkin_submitted';
    }

    public function getEntityClass(): ?string
    {
        return Reservation::class;
    }

    /** @return array<string, mixed> */
    public function getConfigSchema(): array
    {
        return [];
    }

    public function isEventDriven(): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        return $this->configService->isEnabled();
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<Reservation>
     */
    public function findPreviewEntities(EntityManagerInterface $em, array $config, int $limit = 20): array
    {
        /** @var list<GuestCheckIn> $checkIns */
        $checkIns = $em->getRepository(GuestCheckIn::class)->createQueryBuilder('g')
            ->where('g.firstSubmittedAt IS NOT NULL')
            ->orderBy('g.lastSubmittedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(static fn (GuestCheckIn $checkIn): Reservation => $checkIn->getReservation(), $checkIns);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return int[]
     */
    public function findMatchingIds(EntityManagerInterface $em, array $config, int $limit = 500): array
    {
        return [];
    }
}
