<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

use App\Entity\Invoice;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fires exactly X days after an invoice's date.
 *
 * Config: {"days": 14, "runOnDays": "mon_fri", "runAtHour": 9}
 *
 * The cron command runs this trigger on every allowed day. It finds invoices where
 * date = today - N days, so each invoice is a candidate exactly once per configured
 * interval — allowing multiple workflows with different delays (e.g. 14, 30, 60 days).
 * A run that follows excluded weekdays also picks up the invoice dates those days
 * would have handled (see ScheduleWindow), so a due date on a Sunday is mailed on
 * Monday instead of being dropped.
 */
class InvoiceDaysAfterDateTrigger extends AbstractScheduledTrigger
{
    public function getType(): string
    {
        return 'invoice.days_after_date';
    }

    public function getLabelKey(): string
    {
        return 'workflow.trigger.invoice_days_after_date';
    }

    public function getEntityClass(): ?string
    {
        return Invoice::class;
    }

    public function getConfigSchema(): array
    {
        return $this->withScheduleFields([
            [
                'key' => 'days',
                'type' => 'number',
                'label' => 'workflow.trigger.days_after',
                'min' => 1,
                'max' => 365,
                'default' => 14,
            ],
        ]);
    }

    public function findPreviewEntities(EntityManagerInterface $em, array $config, int $limit = 20): array
    {
        [$from, $to] = $this->targetDateRange($config, -$this->days($config), preview: true);

        return $em->getRepository(Invoice::class)->createQueryBuilder('i')
            ->where('i.date BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('i.date', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findMatchingIds(EntityManagerInterface $em, array $config, int $limit = 500): array
    {
        [$from, $to] = $this->targetDateRange($config, -$this->days($config));

        $rows = $em->getRepository(Invoice::class)->createQueryBuilder('i')
            ->select('i.id')
            ->where('i.date BETWEEN :from AND :to')
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
        return (int) ($config['days'] ?? 14);
    }
}
