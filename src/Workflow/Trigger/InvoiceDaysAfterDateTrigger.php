<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

use App\Entity\Invoice;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fires exactly X days after an invoice's date.
 *
 * Config: {"days": 14}
 *
 * The cron command runs this daily. It finds invoices where
 * date = today - N days (exact match). This means each invoice is a
 * candidate exactly once per configured interval — allowing multiple
 * workflows with different delays (e.g. 14 days, 30 days, 60 days).
 */
class InvoiceDaysAfterDateTrigger implements WorkflowTriggerInterface
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
        return [
            [
                'key' => 'days',
                'type' => 'number',
                'label' => 'workflow.trigger.days_after',
                'min' => 1,
                'max' => 365,
                'default' => 14,
            ],
        ];
    }

    public function isEventDriven(): bool
    {
        return false;
    }

    public function findPreviewEntities(EntityManagerInterface $em, array $config, int $limit = 20): array
    {
        $days = (int) ($config['days'] ?? 14);
        $targetDate = (new \DateTimeImmutable('-' . $days . ' days'))->setTime(0, 0, 0);

        return $em->getRepository(Invoice::class)->createQueryBuilder('i')
            ->where('i.date = :targetDate')
            ->setParameter('targetDate', $targetDate)
            ->orderBy('i.date', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findMatchingIds(EntityManagerInterface $em, array $config, int $limit = 500): array
    {
        $days = (int) ($config['days'] ?? 14);
        $targetDate = (new \DateTimeImmutable('-' . $days . ' days'))->setTime(0, 0, 0);

        $rows = $em->getRepository(Invoice::class)->createQueryBuilder('i')
            ->select('i.id')
            ->where('i.date = :targetDate')
            ->setParameter('targetDate', $targetDate)
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'id');
    }
}
