<?php

declare(strict_types=1);

namespace App\Workflow\Action;

use App\Entity\BookingEntry;
use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\InvoicePosition;
use App\Repository\AccountingAccountRepository;
use App\Repository\TaxRateRepository;
use App\Service\BookingJournal\BookingJournalService;
use App\Service\InvoiceService;
use App\Workflow\WorkflowSkippedException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Books a percentage of an invoice as a single entry.
 *
 * Percentage, accounts, tax rate and text all come from the config, so what
 * the deduction represents is a matter of configuration rather than of code.
 * Adding the action more than once to a workflow yields one entry per
 * configured percentage; which invoices it applies to is decided by the
 * workflow's conditions.
 *
 * Config:
 *   percentSource   string   – where the percentage comes from, see the constants
 *   percent         string   – the percentage itself, used when the source is
 *                              manual
 *   amountBase      string   – what the percentage is taken of, see the constants.
 *                              Configured per action, so a commission and a payment
 *                              fee added to the same workflow can each use their own
 *                              base - portals rarely charge both on the same amount
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
    /** The percentage is typed into the workflow. */
    public const PERCENT_SOURCE_MANUAL = 'manual';

    /** The percentage is the commission configured on the invoice's reservation origin. */
    public const PERCENT_SOURCE_COMMISSION = 'origin_commission';

    /** The percentage is the payment fee configured on the invoice's reservation origin. */
    public const PERCENT_SOURCE_PAYMENT_FEE = 'origin_payment_fee';

    /** The percentage is taken of the invoice's full gross total. */
    public const AMOUNT_BASE_GROSS = 'gross';

    /** The percentage is taken of the gross total less the tourist-tax positions. */
    public const AMOUNT_BASE_GROSS_WITHOUT_TOURIST_TAX = 'gross_without_tourist_tax';

    /**
     * Position group the tourist-tax positions carry, see InvoicePosition::$positionGroup.
     * They are the pass-through item this code can single out today; anything else
     * that should stay out of a commission would need its own marker first.
     */
    private const POSITION_GROUP_TOURIST_TAX = 'tourist_tax';

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
                'key' => 'percentSource',
                'type' => 'select',
                'label' => 'workflow.form.percentage_entry_percent_source',
                'help' => 'workflow.form.percentage_entry_percent_source_help',
                'options' => [
                    ['value' => self::PERCENT_SOURCE_MANUAL, 'label' => 'workflow.form.percentage_entry_percent_source_manual'],
                    ['value' => self::PERCENT_SOURCE_COMMISSION, 'label' => 'workflow.form.percentage_entry_percent_source_commission'],
                    ['value' => self::PERCENT_SOURCE_PAYMENT_FEE, 'label' => 'workflow.form.percentage_entry_percent_source_payment_fee'],
                ],
                'default' => self::PERCENT_SOURCE_MANUAL,
            ],
            [
                'key' => 'percent',
                'type' => 'text',
                'label' => 'workflow.form.percentage_entry_percent',
                'help' => 'workflow.form.percentage_entry_percent_help',
                'default' => '',
                // Only relevant when the percentage is typed in, not read from
                // the origin - so it only shows for the manual source.
                'showIf' => ['key' => 'percentSource', 'value' => self::PERCENT_SOURCE_MANUAL],
            ],
            [
                'key' => 'amountBase',
                'type' => 'select',
                'label' => 'workflow.form.percentage_entry_amount_base',
                'help' => 'workflow.form.percentage_entry_amount_base_help',
                'options' => [
                    ['value' => self::AMOUNT_BASE_GROSS_WITHOUT_TOURIST_TAX, 'label' => 'workflow.form.percentage_entry_amount_base_without_tourist_tax'],
                    ['value' => self::AMOUNT_BASE_GROSS, 'label' => 'workflow.form.percentage_entry_amount_base_gross'],
                ],
                // Offered first and preselected: portals charge commission on what
                // the house earns, and tourist tax is collected for the municipality.
                'default' => self::AMOUNT_BASE_GROSS_WITHOUT_TOURIST_TAX,
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

        $percent = $this->resolvePercent($config, $entity);
        if ($percent <= 0.0) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_no_percentage'));
        }

        $base = $this->baseAmount($config, $entity);
        $amount = round($base * $percent / 100.0, 2);
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

        // createEntryFromStatement() serves the bank import and marks what it
        // creates as manual, leaving the source to the caller. This one is not
        // manual: nobody typed it in, a workflow put it there.
        $entry->setSourceType(BookingEntry::SOURCE_WORKFLOW);

        // Defaults to true: workflows configured before the choice existed all
        // book deductions that are documented by a supplier invoice arriving
        // later, and silently dropping the guard would let their month close.
        $entry->setRequiresDocumentNumber('0' !== (string) ($config['requiresDocumentNumber'] ?? '1'));

        // The base goes into the log as a figure: with a configurable base, the
        // percentage alone no longer explains how the amount came about, and the
        // log is where that gets checked against the portal's own statement.
        return $this->translator->trans('workflow.log.percentage_entry_created', [
            // Formatted like the amount beside it: "1,40" rather than PHP's "1.4".
            '%percent%' => number_format($percent, 2, ',', '.'),
            '%amount%' => number_format($amount, 2, ',', '.'),
            '%base%' => number_format($base, 2, ',', '.'),
            '%number%' => (string) $entity->getNumber(),
        ]);
    }

    /**
     * The percentage to book, from wherever the config points it at. Reading it
     * from the reservation origin keeps commission and payment fee in one place
     * shared with what the guest is shown, rather than repeated in each
     * workflow's config where the two could drift apart. An origin source that
     * finds no origin or no value yields zero, which the caller treats as
     * nothing to book - the same as a manual percentage left blank.
     *
     * @param array<string, mixed> $config
     */
    private function resolvePercent(array $config, Invoice $invoice): float
    {
        $source = (string) ($config['percentSource'] ?? self::PERCENT_SOURCE_MANUAL);

        // Anything but the two origin sources uses the typed-in field and has no
        // business looking at the invoice's reservations at all.
        if (self::PERCENT_SOURCE_COMMISSION !== $source && self::PERCENT_SOURCE_PAYMENT_FEE !== $source) {
            return $this->toPercent($config['percent'] ?? '');
        }

        $rates = $this->ratesOnInvoice($invoice, $source);

        // One entry is booked for the whole invoice, so a single rate has to hold
        // for all of it. Two portals on one invoice, or two bookings taken under
        // rates that have changed in between, have no single answer - and the
        // invoice carries no attribution of its lines to reservations to split it
        // along. Booking one of the rates on the full amount would be wrong
        // without ever saying so, so this stops and asks for a manual entry.
        if (count($rates) > 1) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_mixed_rates', [
                '%rates%' => implode(', ', array_keys($rates)),
            ]));
        }

        return 1 === count($rates) ? reset($rates) : 0.0;
    }

    /**
     * The distinct rates the invoice's reservations carry for the given source,
     * keyed by their formatted form so the caller can name them.
     *
     * The rate a reservation was booked under wins over the one its origin carries
     * today: a portal that renegotiates its commission must not change what an
     * invoice from last season is charged. Reservations with no rate recorded fall
     * through to the origin - a pinned rate is an answer whatever it says, an
     * explicit zero included. A reservation without an origin counts as a rate of
     * zero, which is what makes a direct booking sharing an invoice with a portal
     * one show up as the disagreement it is.
     *
     * @return array<string, float>
     */
    private function ratesOnInvoice(Invoice $invoice, string $source): array
    {
        $rates = [];

        foreach ($invoice->getReservations() ?? [] as $reservation) {
            $origin = $reservation->getReservationOrigin();

            $raw = self::PERCENT_SOURCE_COMMISSION === $source
                ? $reservation->getCommissionPercent() ?? $origin?->getCommissionPercent()
                : $reservation->getPaymentFeePercent() ?? $origin?->getPaymentFeePercent();

            $rate = $this->toPercent($raw);
            // Keyed by the formatted figure: it doubles as the label in the log
            // and keeps "12", "12.00" and 12.0 from counting as three rates.
            $rates[number_format($rate, 2, ',', '.').' %'] = $rate;
        }

        return $rates;
    }

    /** Reads a percentage as it may have been typed or stored, commas included. */
    private function toPercent(?string $raw): float
    {
        return (float) str_replace(',', '.', trim((string) $raw));
    }

    /**
     * The amount the percentage is taken of.
     *
     * A config that says nothing is treated like a new one. The field was part
     * of the action from its first release, so no saved workflow predates it.
     */
    private function baseAmount(array $config, Invoice $invoice): float
    {
        $positions = $invoice->getPositions() ?? new ArrayCollection();

        if (self::AMOUNT_BASE_GROSS_WITHOUT_TOURIST_TAX === (string) ($config['amountBase'] ?? self::AMOUNT_BASE_GROSS_WITHOUT_TOURIST_TAX)) {
            // Dropped before the sum rather than subtracted afterwards, so the
            // per-VAT-rate rounding inside calculateSums stays the one the
            // remaining positions produce on their own.
            $positions = $positions->filter(
                fn (InvoicePosition $position): bool => self::POSITION_GROUP_TOURIST_TAX !== $position->getPositionGroup()
            );
        }

        return $this->grossTotal($invoice->getAppartments() ?? new ArrayCollection(), $positions);
    }

    /**
     * Gross total of the given parts, calculated the same way the invoice itself
     * calculates the figure it shows.
     *
     * @param Collection<int, InvoiceAppartment> $apartments
     * @param Collection<int, InvoicePosition>   $positions
     */
    private function grossTotal(Collection $apartments, Collection $positions): float
    {
        $brutto = 0.0;
        $netto = 0.0;
        $apartmentTotal = 0.0;
        $miscTotal = 0.0;
        $vats = [];

        $this->invoiceService->calculateSums(
            $apartments,
            $positions,
            $vats,
            $brutto,
            $netto,
            $apartmentTotal,
            $miscTotal,
        );

        return $brutto;
    }
}
