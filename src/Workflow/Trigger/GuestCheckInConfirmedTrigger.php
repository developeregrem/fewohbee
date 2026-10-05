<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use App\Service\GuestCheckIn\GuestCheckInConfigService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fires when staff confirmed a guest's online check-in, i.e. the data is in the guest records
 * now — the moment to hand out what only checked-in guests should get. Only offered while the
 * online check-in is switched on.
 */
class GuestCheckInConfirmedTrigger implements ConditionalWorkflowTriggerInterface
{
    public const TYPE = 'guest_checkin.confirmed';

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
        return 'workflow.trigger.guest_checkin_confirmed';
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
            ->where('g.appliedAt IS NOT NULL')
            ->orderBy('g.appliedAt', 'DESC')
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
