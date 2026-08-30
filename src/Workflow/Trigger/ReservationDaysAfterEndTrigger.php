<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

use App\Entity\Reservation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fires X days after a reservation's end date (departure).
 *
 * Config: {"days": 1, "runOnDays": "mon_fri", "runAtHour": 9}
 *
 * The cron command runs this trigger on every allowed day. It finds reservations
 * whose end_date is today - N days; a run that follows excluded weekdays also picks
 * up the end dates those days would have handled (see ScheduleWindow).
 */
class ReservationDaysAfterEndTrigger extends AbstractScheduledTrigger
{
    public function getType(): string
    {
        return 'reservation.days_after_end';
    }

    public function getLabelKey(): string
    {
        return 'workflow.trigger.reservation_days_after_end';
    }

    public function getEntityClass(): ?string
    {
        return Reservation::class;
    }

    public function getConfigSchema(): array
    {
        return $this->withScheduleFields([
            [
                'key' => 'days',
                'type' => 'number',
                'label' => 'workflow.trigger.days_after',
                'min' => 0,
                'max' => 365,
                'default' => 1,
            ],
        ]);
    }

    public function findPreviewEntities(EntityManagerInterface $em, array $config, int $limit = 20): array
    {
        [$from, $to] = $this->targetDateRange($config, -$this->days($config), preview: true);

        return $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->where('r.endDate BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('r.endDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findMatchingIds(EntityManagerInterface $em, array $config, int $limit = 500): array
    {
        [$from, $to] = $this->targetDateRange($config, -$this->days($config));

        $rows = $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->select('r.id')
            ->where('r.endDate BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'id');
    }

    /** @param array<string, mixed> $config */
    private function days(array $config): int
    {
        return (int) ($config['days'] ?? 1);
    }
}
