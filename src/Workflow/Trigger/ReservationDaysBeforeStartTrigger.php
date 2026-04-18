<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

use App\Entity\Reservation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fires X days before a reservation's start date.
 *
 * Config: {"days": 3}
 *
 * The cron command runs this trigger daily. It finds reservations
 * whose start_date is exactly today + N days.
 */
class ReservationDaysBeforeStartTrigger implements WorkflowTriggerInterface
{
    public function getType(): string
    {
        return 'reservation.days_before_start';
    }

    public function getLabelKey(): string
    {
        return 'workflow.trigger.reservation_days_before_start';
    }

    public function getEntityClass(): ?string
    {
        return Reservation::class;
    }

    public function getConfigSchema(): array
    {
        return [
            [
                'key' => 'days',
                'type' => 'number',
                'label' => 'workflow.trigger.days_before',
                'min' => 0,
                'max' => 365,
                'default' => 3,
            ],
        ];
    }

    public function isEventDriven(): bool
    {
        return false;
    }

    public function findPreviewEntities(EntityManagerInterface $em, array $config, int $limit = 20): array
    {
        $days = (int) ($config['days'] ?? 3);
        $targetDate = (new \DateTimeImmutable('+' . $days . ' days'))->setTime(0, 0, 0);

        return $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->where('r.startDate = :targetDate')
            ->setParameter('targetDate', $targetDate)
            ->orderBy('r.startDate', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findMatchingIds(EntityManagerInterface $em, array $config, int $limit = 500): array
    {
        $days = (int) ($config['days'] ?? 3);
        $targetDate = (new \DateTimeImmutable('+' . $days . ' days'))->setTime(0, 0, 0);

        $rows = $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->select('r.id')
            ->where('r.startDate = :targetDate')
            ->setParameter('targetDate', $targetDate)
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'id');
    }
}
