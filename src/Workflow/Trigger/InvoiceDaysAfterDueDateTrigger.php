<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use App\Service\EInvoice\EInvoiceReadinessService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fires X days after an invoice's payment due date.
 *
 * Config: {"days": 1, "runOnDays": "mon_fri", "runAtHour": 9}
 *
 * The due date is the one the invoice prints (Invoice::resolvePaymentDueDate()): the
 * invoice's own date, else its date plus the payment period of the issuer that applies
 * to it. "days" is signed - 0 fires on the due date, -3 three days before it - so a
 * reminder and a "last day to pay" mail follow an invoice given a later date instead
 * of going out while it is not yet due, as "X days after invoice date" would.
 *
 * A condition could not achieve this: a scheduled trigger offers each invoice on one
 * day only, and a condition can refuse that day but not postpone it.
 */
class InvoiceDaysAfterDueDateTrigger extends AbstractScheduledTrigger
{
    public const TYPE = 'invoice.days_after_due_date';

    public function __construct(private readonly EInvoiceReadinessService $settingsResolver)
    {
    }

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

        return \array_slice($this->findDueBetween($em, $dueDate, $dueDate), 0, $limit);
    }

    /** @param array<string, mixed> $config */
    public function findMatchingIds(EntityManagerInterface $em, array $config, int $limit = 500): array
    {
        [$from, $to] = $this->targetDateRange($config, -$this->days($config));

        $ids = array_map(static fn (Invoice $invoice): int => (int) $invoice->getId(), $this->findDueBetween($em, $from, $to));

        return \array_slice($ids, 0, $limit);
    }

    /**
     * Invoices whose due date lies in [from, to], newest invoice date first.
     *
     * An invoice without a date of its own is due by its issuer's period, which the
     * database cannot resolve per invoice. The query therefore narrows by the periods
     * configured anywhere, and resolvePaymentDueDate() decides for each candidate.
     *
     * @return list<Invoice>
     */
    private function findDueBetween(EntityManagerInterface $em, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $periods = array_map('intval', array_column($em->createQueryBuilder()
            ->select('DISTINCT s.paymentDueDays AS days')
            ->from(InvoiceSettingsData::class, 's')
            ->where('s.paymentDueDays IS NOT NULL')
            ->getQuery()
            ->getScalarResult(), 'days'));

        $qb = $em->getRepository(Invoice::class)->createQueryBuilder('i')
            ->where('i.paymentDueDate BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('i.date', 'DESC');

        if ([] !== $periods) {
            $qb->orWhere('i.paymentDueDate IS NULL AND i.date BETWEEN :dateFrom AND :dateTo')
                ->setParameter('dateFrom', $from->modify('-'.max($periods).' days'))
                ->setParameter('dateTo', $to->modify('-'.min($periods).' days'));
        }

        $due = [];
        foreach ($qb->getQuery()->getResult() as $invoice) {
            $dueDate = $invoice->resolvePaymentDueDate($this->settingsResolver->resolveSettingsFor($invoice));
            if (null !== $dueDate && $dueDate >= $from && $dueDate <= $to) {
                $due[] = $invoice;
            }
        }

        return $due;
    }

    /** @param array<string, mixed> $config */
    private function days(array $config): int
    {
        return (int) ($config['days'] ?? 1);
    }
}
