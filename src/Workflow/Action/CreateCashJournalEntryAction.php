<?php

declare(strict_types=1);

namespace App\Workflow\Action;

use App\Entity\Invoice;
use App\Service\CashJournalService;
use App\Workflow\WorkflowSkippedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creates a CashJournalEntry from an Invoice.
 *
 * Intended for use with the invoice.status_changed trigger + InvoiceStatusCondition
 * to automatically book a payment into the cash journal when an invoice is marked as paid.
 *
 * Config:
 *   remark  string  – optional remark stored on the journal entry
 */
class CreateCashJournalEntryAction implements WorkflowActionInterface
{
    public function __construct(
        private readonly CashJournalService $cashJournalService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getType(): string
    {
        return 'create_cash_journal_entry';
    }

    public function getLabelKey(): string
    {
        return 'workflow.action.create_cash_journal_entry';
    }

    public function getSupportedEntityClasses(): array
    {
        return [Invoice::class];
    }

    public function getSupportedTriggerTypes(): array
    {
        return [];
    }

    public function getConfigSchema(): array
    {
        return [
            [
                'key' => 'remark',
                'type' => 'text',
                'label' => 'workflow.form.cash_journal_remark',
                'default' => '',
            ],
        ];
    }

    public function execute(array $config, mixed $entity, array $context): string
    {
        if (!$entity instanceof Invoice) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_unsupported_entity'));
        }

        $remark = trim($config['remark'] ?? '') ?: null;

        $entry = $this->cashJournalService->createEntryFromInvoice($entity, $remark);

        return $this->translator->trans('workflow.log.cash_journal_entry_created', [
            '%number%' => $entry->getInvoiceNumber(),
            '%doc%' => $entry->getDocumentNumber(),
        ]);
    }
}
