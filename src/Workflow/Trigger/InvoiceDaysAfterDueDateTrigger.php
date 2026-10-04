<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

use App\Entity\Invoice;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fires X days after an invoice's payment due date.
 *
 * Config: {"days": 1, "runOnDays": "mon_fri", "runAtHour": 9}
 *
 * The due date is the one stored on the invoice and printed on it. "days" is signed -
 * 0 fires on the due date, -3 three days before it - so a reminder follows an invoice
 * given a later due date instead of going out while it is not yet due, as "X days
 * after invoice date" would.
 *
 * A condition could not achieve this: a scheduled trigger offers each invoice on one
 * day only, and a condition can refuse that day but not postpone it.
 */
class InvoiceDaysAfterDueDateTrigger extends AbstractScheduledTrigger
{
    public const TYPE = 'invoice.days_after_due_date';

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getLabelKey(): string
    {
        return 'workflow.trigger.invoice_days_after_due_date';
    }

    public function getEntityClass(): ?string
    {
        return Invoice::class;
    }

    /** @return list<array<string, mixed>> */
    public function getConfigSchema(): array
    {
        return $this->withScheduleFields([
            [
                'key' => 'days',
                'type' => 'number',
                'label' => 'workflow.trigger.days_after_due_date',
                'help' => 'workflow.trigger.days_after_due_date_help',
                'min' => -30,
                'max' => 365,
                'default' => 1,
            ],
        ]);
    }

    /** @param array<string, mixed> $config */
    public function findPreviewEntities(EntityManagerInterface $em, array $config, int $limit = 20): array
    {
        // The preview shows what the rule covers today; the schedule is not applied here.
        $dueDate = $this->previewDate(-$this->days($config));

        return $em->getRepository(Invoice::class)->createQueryBuilder('i')
            ->where('i.paymentDueDate = :dueDate')
            ->setParameter('dueDate', $dueDate)
            ->orderBy('i.date', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @param array<string, mixed> $config */
    public function findMatchingIds(EntityManagerInterface $em, array $config, int $limit = 500): array
    {
        [$from, $to] = $this->targetDateRange($config, -$this->days($config));

        $rows = $em->getRepository(Invoice::class)->createQueryBuilder('i')
            ->select('i.id')
            ->where('i.paymentDueDate BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_map('intval', array_column($rows, 'id'));
    }

    /** @param array<string, mixed> $config */
    private function days(array $config): int
    {
        return (int) ($config['days'] ?? 1);
    }
}
