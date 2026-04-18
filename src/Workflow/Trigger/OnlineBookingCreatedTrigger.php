<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

use App\Entity\Reservation;
use Doctrine\ORM\EntityManagerInterface;

class OnlineBookingCreatedTrigger implements WorkflowTriggerInterface
{
    public function getType(): string
    {
        return 'online_booking.created';
    }

    public function getLabelKey(): string
    {
        return 'workflow.trigger.online_booking_created';
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
            ->innerJoin('r.calendarSyncImport', 'csi')
            ->where('csi IS NULL')
            ->andWhere('r.booker IS NOT NULL')
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
