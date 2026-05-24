<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\TemplatePreview;

use App\Entity\AccountingAccount;
use App\Entity\BookingBatch;
use App\Entity\BookingEntry;
use App\Entity\Template;
use App\Entity\TaxRate;
use App\Interfaces\ITemplatePreviewProvider;
use App\Repository\BookingBatchRepository;
use App\Service\BookingJournal\BookingJournalService;

/**
 * Preview provider for cash journal PDF templates.
 */
class CashJournalTemplatePreviewProvider implements ITemplatePreviewProvider
{
    public function __construct(
        private readonly BookingBatchRepository $batchRepo,
        private readonly BookingJournalService $bookingJournalService,
    ) {
    }

    public function supportsPreview(Template $template): bool
    {
        return $template->getTemplateType()?->getName() === 'TEMPLATE_CASHJOURNAL_PDF';
    }

    public function getPreviewContextDefinition(): array
    {
        return [
            [
                'name' => 'year',
                'type' => 'text',
                'label' => 'templates.preview.cashjournal.year',
                'placeholder' => 'templates.preview.cashjournal.year.placeholder',
                'help' => 'templates.preview.cashjournal.year.help',
            ],
            [
                'name' => 'month',
                'type' => 'text',
                'label' => 'templates.preview.cashjournal.month',
                'placeholder' => 'templates.preview.cashjournal.month.placeholder',
                'help' => 'templates.preview.cashjournal.month.help',
            ],
        ];
    }

    public function buildSampleContext(): array
    {
        $batch = $this->batchRepo->getYoungestBatch();
        if (null !== $batch) {
            return [
                'year' => (string) $batch->getYear(),
                'month' => (string) $batch->getMonth(),
            ];
        }

        return [];
    }

    public function buildPreviewRenderParams(Template $template, array $ctx): array
    {
        $yearRaw = is_scalar($ctx['year'] ?? null) ? trim((string) $ctx['year']) : '';
        $monthRaw = is_scalar($ctx['month'] ?? null) ? trim((string) $ctx['month']) : '';
        $hasInput = ('' !== $yearRaw || '' !== $monthRaw);
        if ($hasInput) {
            $params = $this->buildRenderParams($template, [
                'year' => $yearRaw,
                'month' => $monthRaw,
            ]);
            if (!empty($params)) {
                return $params;
            }

            $ctx['_previewWarning'] = 'templates.preview.cashjournal.notfound';
            $ctx['_previewWarningVars'] = ['%value%' => trim($yearRaw.'-'.$monthRaw, '-')];

            return $this->buildSampleParams($ctx);
        }

        $latest = $this->batchRepo->getYoungestBatch();
        if (null !== $latest) {
            return $this->bookingJournalService->buildTemplateRenderParams($latest->getId());
        }

        return $this->buildSampleParams($ctx);
    }

    public function buildRenderParams(Template $template, mixed $input): array
    {
        $batch = null;
        if ($input instanceof BookingBatch) {
            $batch = $input;
        } elseif (is_numeric($input)) {
            $batch = $this->batchRepo->find((int) $input);
        } elseif (is_array($input)) {
            $yearRaw = is_scalar($input['year'] ?? ($input['cashYear'] ?? null)) ? trim((string) ($input['year'] ?? $input['cashYear'])) : '';
            $monthRaw = is_scalar($input['month'] ?? ($input['cashMonth'] ?? null)) ? trim((string) ($input['month'] ?? $input['cashMonth'])) : '';
            $year = is_numeric($yearRaw) ? (int) $yearRaw : null;
            $month = is_numeric($monthRaw) ? (int) $monthRaw : null;
            $isMonthValid = null !== $month && $month >= 1 && $month <= 12;
            if (null !== $year && $isMonthValid) {
                $batch = $this->batchRepo->findByYearAndMonth($year, $month);
            }
        }

        if (!$batch instanceof BookingBatch) {
            return [];
        }

        return $this->bookingJournalService->buildTemplateRenderParams($batch->getId());
    }

    public function getRenderParamsSchema(): array
    {
        return [
            'journal' => ['class' => BookingBatch::class],
        ];
    }

    public function getAvailableSnippets(): array
    {
        return [
            [
                'id' => 'bookingjournal.month',
                'label' => 'templates.preview.snippet.bookingjournal.month',
                'group' => 'bookingjournal',
                'complexity' => 'simple',
                'content' => '[[ journal.month ]]',
            ],
            [
                'id' => 'bookingjournal.year',
                'label' => 'templates.preview.snippet.bookingjournal.year',
                'group' => 'bookingjournal',
                'complexity' => 'simple',
                'content' => '[[ journal.year ]]',
            ],
            [
                'id' => 'bookingjournal.period',
                'label' => 'templates.preview.snippet.bookingjournal.period',
                'group' => 'bookingjournal',
                'complexity' => 'simple',
                'content' => "[[ getLocalizedMonth(journal.month, 'MMMM', app.request.locale) ]] [[ journal.year ]]",
            ],
            [
                'id' => 'bookingjournal.entries.count',
                'label' => 'templates.preview.snippet.bookingjournal.entries_count',
                'group' => 'bookingjournal',
                'complexity' => 'simple',
                'content' => '[[ journal.entries|length ]]',
            ],
            [
                'id' => 'bookingjournal.positions',
                'label' => 'templates.preview.snippet.bookingjournal.positions',
                'group' => 'bookingjournal',
                'complexity' => 'easy',
                'content' => "<table style=\"width: 100%; border-collapse: collapse;\" border=\"0\" cellspacing=\"0\"><tr><th style=\"text-align: left;\">{{ 'accounting.journal.entry.doc_number'|trans }}</th><th style=\"text-align: left;\">{{ 'accounting.journal.entry.date'|trans }}</th><th style=\"text-align: left;\">{{ 'accounting.journal.entry.debit'|trans }}</th><th style=\"text-align: left;\">{{ 'accounting.journal.entry.credit'|trans }}</th><th style=\"text-align: right;\">{{ 'accounting.journal.entry.amount'|trans }}</th><th style=\"text-align: center;\">{{ 'accounting.journal.entry.tax_rate'|trans }}</th><th style=\"text-align: left;\">{{ 'accounting.journal.entry.invoice'|trans }}</th><th style=\"text-align: left;\">{{ 'accounting.journal.entry.remark'|trans }}</th></tr><tr data-repeat=\"journal.entries\" data-repeat-as=\"entry\"><td>[[ entry.documentNumberF ]]</td><td>[[ entry.date|date('d.m.Y') ]]</td><td>[[ entry.debitAccount ? entry.debitAccount.label : '–' ]]</td><td>[[ entry.creditAccount ? entry.creditAccount.label : '–' ]]</td><td style=\"text-align: right;\">[[ entry.amountF ]] €</td><td style=\"text-align: center;\">[[ entry.taxRate ? entry.taxRate.rate : '0.00' ]] %</td><td>[[ entry.invoiceNumber ?: '–' ]]</td><td>[[ entry.remark ?: '–' ]]</td></tr></table>",
            ],
            [
                'id' => 'pdf.header',
                'label' => 'templates.preview.snippet.pdf_header',
                'group' => 'PDF',
                'complexity' => 'simple',
                'content' => '<div class="header"><p>Header</p></div>',
            ],
            [
                'id' => 'pdf.footer',
                'label' => 'templates.preview.snippet.pdf_footer',
                'group' => 'PDF',
                'complexity' => 'simple',
                'content' => '<div class="footer"><p>Footer</p></div>',
            ],
        ];
    }

    /**
     * Build minimal in-memory sample data when no batch exists yet.
     *
     * @param array<string, mixed> $ctx
     *
     * @return array<string, mixed>
     */
    private function buildSampleParams(array $ctx = []): array
    {
        $batch = new BookingBatch();
        $batch->setMonth((int) date('n'));
        $batch->setYear((int) date('Y'));

        $assetAccount = (new AccountingAccount())
            ->setAccountNumber('1200')
            ->setName('Bank')
            ->setType(AccountingAccount::TYPE_ASSET);
        $revenueAccount = (new AccountingAccount())
            ->setAccountNumber('8400')
            ->setName('Erlöse 19 % USt')
            ->setType(AccountingAccount::TYPE_REVENUE);
        $expenseAccount = (new AccountingAccount())
            ->setAccountNumber('4930')
            ->setName('Bürobedarf')
            ->setType(AccountingAccount::TYPE_EXPENSE);
        $vat19 = (new TaxRate())
            ->setName('19 % USt')
            ->setRate('19.00')
            ->setRevenueAccount($revenueAccount);

        $entry1 = (new BookingEntry())
            ->setDate(new \DateTime('first day of this month'))
            ->setDocumentNumber(1001)
            ->setAmount('245.00')
            ->setDebitAccount($assetAccount)
            ->setCreditAccount($revenueAccount)
            ->setTaxRate($vat19)
            ->setInvoiceNumber('RE-1001')
            ->setRemark('Zahlung Zimmerrechnung')
            ->setSourceType(BookingEntry::SOURCE_MANUAL);

        $entry2 = (new BookingEntry())
            ->setDate(new \DateTime('first day of this month +3 days'))
            ->setDocumentNumber(1002)
            ->setAmount('58.40')
            ->setDebitAccount($expenseAccount)
            ->setCreditAccount($assetAccount)
            ->setTaxRate($vat19)
            ->setInvoiceNumber(null)
            ->setRemark('Einkauf Bürobedarf')
            ->setSourceType(BookingEntry::SOURCE_MANUAL);

        $entry3 = (new BookingEntry())
            ->setDate(new \DateTime('first day of this month +5 days'))
            ->setDocumentNumber(1003)
            ->setAmount('120.00')
            ->setDebitAccount($assetAccount)
            ->setCreditAccount($revenueAccount)
            ->setTaxRate($vat19)
            ->setInvoiceNumber('RE-1002')
            ->setRemark('Nachzahlung Rechnung')
            ->setSourceType(BookingEntry::SOURCE_WORKFLOW);

        $batch->addEntry($entry1);
        $batch->addEntry($entry2);
        $batch->addEntry($entry3);

        return array_merge([
            'journal' => $batch,
        ], $ctx);
    }
}
