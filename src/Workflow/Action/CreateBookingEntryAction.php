<?php

declare(strict_types=1);

namespace App\Workflow\Action;

use App\Entity\Invoice;
use App\Repository\AccountingAccountRepository;
use App\Service\BookingJournal\BookingJournalService;
use App\Workflow\WorkflowSkippedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creates BookingEntries from an Invoice, one per VAT rate.
 *
 * The debit account (e.g. cash, bank) is configurable. Credit accounts and
 * tax rates are resolved automatically from the TaxRate entity's revenueAccount
 * and the invoice's VAT breakdown. An optional fallback credit account can be set.
 *
 * Config:
 *   debitAccountId          int|null  – account for the debit side (e.g. cash)
 *   fallbackCreditAccountId int|null  – fallback credit account when TaxRate has no revenueAccount
 *   remark                  string    – optional remark stored on each entry
 */
class CreateBookingEntryAction implements WorkflowActionInterface
{
    public function __construct(
        private readonly BookingJournalService $bookingJournalService,
        private readonly AccountingAccountRepository $accountRepo,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getType(): string
    {
        return 'create_booking_entry';
    }

    public function getLabelKey(): string
    {
        return 'workflow.action.create_booking_entry';
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
                'key' => 'debitAccountId',
                'type' => 'accounting_account_select',
                'label' => 'workflow.form.debit_account',
                'help' => 'workflow.form.debit_account_help',
                'default' => '',
            ],
            [
                'key' => 'fallbackCreditAccountId',
                'type' => 'accounting_account_select',
                'label' => 'workflow.form.fallback_credit_account',
                'help' => 'workflow.form.fallback_credit_account_help',
                'default' => '',
            ],
            [
                'key' => 'remark',
                'type' => 'text',
                'label' => 'workflow.form.booking_entry_remark',
                'help' => 'workflow.form.booking_entry_remark_help',
                'default' => '',
            ],
        ];
    }

    public function execute(array $config, mixed $entity, array $context): string
    {
        if (!$entity instanceof Invoice) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_unsupported_entity'));
        }

        $debitAccount = null;
        $fallbackCreditAccount = null;

        if (!empty($config['debitAccountId'])) {
            $debitAccount = $this->accountRepo->find((int) $config['debitAccountId']);
        }

        if (!empty($config['fallbackCreditAccountId'])) {
            $fallbackCreditAccount = $this->accountRepo->find((int) $config['fallbackCreditAccountId']);
        }

        $remark = trim($config['remark'] ?? '') ?: null;

        $entries = $this->bookingJournalService->createEntriesFromInvoice(
            $entity,
            $debitAccount,
            $fallbackCreditAccount,
            $remark,
        );

        if (0 === count($entries)) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_no_amounts'));
        }

        return $this->translator->trans('workflow.log.booking_entries_created', [
            '%number%' => $entries[0]->getInvoiceNumber(),
            '%count%' => count($entries),
        ]);
    }
}
