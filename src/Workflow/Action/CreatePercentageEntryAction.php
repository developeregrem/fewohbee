<?php

declare(strict_types=1);

namespace App\Workflow\Action;

use App\Entity\Invoice;
use App\Repository\AccountingAccountRepository;
use App\Repository\TaxRateRepository;
use App\Service\BookingJournal\BookingJournalService;
use App\Service\InvoiceService;
use App\Workflow\WorkflowSkippedException;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Books a percentage of an invoice's gross total as a single entry.
 *
 * Percentage, accounts, tax rate and text all come from the config, so what
 * the deduction represents is a matter of configuration rather than of code.
 * Adding the action more than once to a workflow yields one entry per
 * configured percentage; which invoices it applies to is decided by the
 * workflow's conditions.
 *
 * Config:
 *   percent         string   – percentage of the invoice's gross total
 *   debitAccountId  int|null – expense (or reverse-charge) account
 *   creditAccountId int|null – account the deduction is taken from, usually the
 *                              same one the invoice itself was booked against
 *   taxRateId       int|null – tax rate recorded on the entry
 *   remark          string   – free text, %number% is replaced by the invoice number
 *   requiresDocumentNumber string – '1' marks the entry as waiting for a document
 *                              reference, which also holds the month open
 */
class CreatePercentageEntryAction implements WorkflowActionInterface
{
    public function __construct(
        private readonly BookingJournalService $bookingJournalService,
        private readonly AccountingAccountRepository $accountRepo,
        private readonly TaxRateRepository $taxRateRepo,
        private readonly InvoiceService $invoiceService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getType(): string
    {
        return 'create_percentage_entry';
    }

    public function getLabelKey(): string
    {
        return 'workflow.action.create_percentage_entry';
    }

    public function getSupportedEntityClasses(): array
    {
        return [Invoice::class];
    }

    public function getSupportedTriggerTypes(): array
    {
        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        return [
            [
                'key' => 'percent',
                'type' => 'text',
                'label' => 'workflow.form.percentage_entry_percent',
                'help' => 'workflow.form.percentage_entry_percent_help',
                'default' => '',
            ],
            [
                'key' => 'debitAccountId',
                'type' => 'accounting_account_select',
                'label' => 'workflow.form.percentage_entry_debit_account',
                'help' => 'workflow.form.percentage_entry_debit_account_help',
                'default' => '',
            ],
            [
                'key' => 'creditAccountId',
                'type' => 'accounting_account_select',
                'label' => 'workflow.form.percentage_entry_credit_account',
                'help' => 'workflow.form.percentage_entry_credit_account_help',
                'default' => '',
            ],
            [
                'key' => 'taxRateId',
                // Resolved by the controller, which scopes the list to the chart
                // of accounts in use - an action has no business knowing which
                // one that is.
                'type' => 'tax_rate_select',
                'label' => 'workflow.form.percentage_entry_tax_rate',
                'default' => '',
            ],
            [
                'key' => 'remark',
                'type' => 'text',
                'label' => 'workflow.form.percentage_entry_remark',
                'help' => 'workflow.form.percentage_entry_remark_help',
                'default' => '',
            ],
            [
                'key' => 'requiresDocumentNumber',
                'type' => 'select',
                'label' => 'workflow.form.percentage_entry_requires_document',
                'help' => 'workflow.form.percentage_entry_requires_document_help',
                'options' => [
                    ['value' => '1', 'label' => 'workflow.form.percentage_entry_requires_document_yes'],
                    ['value' => '0', 'label' => 'workflow.form.percentage_entry_requires_document_no'],
                ],
                'default' => '1',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    public function execute(array $config, mixed $entity, array $context): string
    {
        if (!$entity instanceof Invoice) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_unsupported_entity'));
        }

        $percent = (float) str_replace(',', '.', trim((string) ($config['percent'] ?? '')));
        if ($percent <= 0.0) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_no_percentage'));
        }

        $amount = round($this->grossTotal($entity) * $percent / 100.0, 2);
        if (0.0 === $amount) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_no_amounts'));
        }

        $remark = str_replace('%number%', (string) $entity->getNumber(), trim((string) ($config['remark'] ?? '')));

        $entry = $this->bookingJournalService->createEntryFromStatement(
            // The day the workflow runs, not the invoice date. The action is
            // meant for a workflow that runs when the invoice is marked as paid,
            // so today is the day the payment was recorded - and a deduction
            // settled out of that payment belongs in the month it was settled,
            // not in the one the invoice was written in. Nothing enforces that
            // trigger, so the form says which day the entry gets.
            new \DateTime('today'),
            number_format($amount, 2, '.', ''),
            !empty($config['debitAccountId']) ? $this->accountRepo->find((int) $config['debitAccountId']) : null,
            !empty($config['creditAccountId']) ? $this->accountRepo->find((int) $config['creditAccountId']) : null,
            '' !== $remark ? $remark : null,
            // No document reference yet: the one that belongs here is the
            // supplier's invoice for the deduction, which is issued later and
            // usually covers several invoices at once. The entry is flagged as
            // waiting for it below, and a batch will not close until it has one.
            null,
            // Deliberately no invoiceId either: the bank import re-dates every
            // entry carrying one when a statement line matches that invoice,
            // which would drag the deduction to the payout date - and a payout
            // covering several invoices says nothing about any single one of
            // them. The date is chosen above; the bank import must not override
            // it. The invoice number stays traceable through the remark.
            null,
            null,
            !empty($config['taxRateId']) ? $this->taxRateRepo->find((int) $config['taxRateId']) : null,
        );

        // Defaults to true: workflows configured before the choice existed all
        // book deductions that are documented by a supplier invoice arriving
        // later, and silently dropping the guard would let their month close.
        $entry->setRequiresDocumentNumber('0' !== (string) ($config['requiresDocumentNumber'] ?? '1'));

        return $this->translator->trans('workflow.log.percentage_entry_created', [
            // Formatted like the amount beside it: "1,40" rather than PHP's "1.4".
            '%percent%' => number_format($percent, 2, ',', '.'),
            '%amount%' => number_format($amount, 2, ',', '.'),
            '%number%' => (string) $entity->getNumber(),
        ]);
    }

    /** Gross total of the invoice, the same figure the invoice itself shows. */
    private function grossTotal(Invoice $invoice): float
    {
        $brutto = 0.0;
        $netto = 0.0;
        $apartmentTotal = 0.0;
        $miscTotal = 0.0;
        $vats = [];

        $this->invoiceService->calculateSums(
            $invoice->getAppartments() ?? new ArrayCollection(),
            $invoice->getPositions() ?? new ArrayCollection(),
            $vats,
            $brutto,
            $netto,
            $apartmentTotal,
            $miscTotal,
        );

        return $brutto;
    }
}
