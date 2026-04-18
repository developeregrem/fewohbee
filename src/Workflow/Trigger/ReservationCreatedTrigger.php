<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

use App\Entity\Reservation;
use Doctrine\ORM\EntityManagerInterface;

class ReservationCreatedTrigger implements WorkflowTriggerInterface
{
    public function getType(): string
    {
        return 'reservation.created';
    }

    public function getLabelKey(): string
    {
        return 'workflow.trigger.reservation_created';
    }

    public function getEntityClass(): ?string
    {
        return Reservation::class;
    }

    public function getConfigSchema(): array
    {
        return [];
    }

    public function isEventDriven(): bool
    {
        return true;
    }

    public function findPreviewEntities(EntityManagerInterface $em, array $config, int $limit = 20): array
    {
        return $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->leftJoin('r.calendarSyncImport', 'csi')
            ->where('csi IS NULL')
            ->orderBy('r.reservationDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findMatchingIds(EntityManagerInterface $em, array $config, int $limit = 500): array
    {
        return [];
    }
}
