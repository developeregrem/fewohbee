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

use App\Entity\CashJournal;
use App\Entity\CashJournalEntry;
use App\Entity\Template;
use App\Interfaces\ITemplatePreviewProvider;
use App\Service\CashJournalService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Preview provider for cash journal PDF templates.
 */
class CashJournalTemplatePreviewProvider implements ITemplatePreviewProvider
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CashJournalService $cashJournalService
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
                'name' => 'cashYear',
                'type' => 'text',
                'label' => 'templates.preview.cashjournal.year',
                'placeholder' => 'templates.preview.cashjournal.year.placeholder',
                'help' => 'templates.preview.cashjournal.year.help',
            ],
            [
                'name' => 'cashMonth',
                'type' => 'text',
                'label' => 'templates.preview.cashjournal.month',
                'placeholder' => 'templates.preview.cashjournal.month.placeholder',
                'help' => 'templates.preview.cashjournal.month.help',
            ],
        ];
    }

    public function buildSampleContext(): array
    {
        $journal = $this->em->getRepository(CashJournal::class)->findOneBy([], ['id' => 'DESC']);
        if ($journal instanceof CashJournal) {
            return [
                'cashYear' => (string) $journal->getCashYear(),
                'cashMonth' => (string) $journal->getCashMonth(),
            ];
        }

        return [];
    }

    public function buildPreviewRenderParams(Template $template, array $ctx): array
    {
        $cashYearRaw = is_scalar($ctx['cashYear'] ?? null) ? trim((string) $ctx['cashYear']) : '';
        $cashMonthRaw = is_scalar($ctx['cashMonth'] ?? null) ? trim((string) $ctx['cashMonth']) : '';
        $hasInput = ($cashYearRaw !== '' || $cashMonthRaw !== '');
        if ($hasInput) {
            $params = $this->buildRenderParams($template, [
                'cashYear' => $cashYearRaw,
                'cashMonth' => $cashMonthRaw,
            ]);
            if (!empty($params)) {
                return $params;
            }

            $ctx['_previewWarning'] = 'templates.preview.cashjournal.notfound';
            $ctx['_previewWarningVars'] = ['%value%' => trim($cashYearRaw.'-'.$cashMonthRaw, '-')];

            return $this->buildSampleParams($ctx);
        }

        $latest = $this->em->getRepository(CashJournal::class)->findOneBy([], ['id' => 'DESC']);
        if ($latest instanceof CashJournal) {
            return $this->cashJournalService->buildTemplateRenderParams($template, $latest->getId());
        }

        return $this->buildSampleParams($ctx);
    }

    public function buildRenderParams(Template $template, mixed $input): array
    {
        $journal = null;
        if ($input instanceof CashJournal) {
            $journal = $input;
        } elseif (is_numeric($input)) {
            $journal = $this->em->getRepository(CashJournal::class)->find((int) $input);
        } elseif (is_array($input)) {
            $cashYearRaw = is_scalar($input['cashYear'] ?? null) ? trim((string) $input['cashYear']) : '';
            $cashMonthRaw = is_scalar($input['cashMonth'] ?? null) ? trim((string) $input['cashMonth']) : '';
            $year = is_numeric($cashYearRaw) ? (int) $cashYearRaw : null;
            $month = is_numeric($cashMonthRaw) ? (int) $cashMonthRaw : null;
            $isMonthValid = null !== $month && $month >= 1 && $month <= 12;
            if (null !== $year && $isMonthValid) {
                $journal = $this->em->getRepository(CashJournal::class)->findOneBy([
                    'cashYear' => $year,
                    'cashMonth' => $month,
                ], ['id' => 'DESC']);
            }
        }

        if (!$journal instanceof CashJournal) {
            return [];
        }

        return $this->cashJournalService->buildTemplateRenderParams($template, $journal->getId());
    }

    public function getRenderParamsSchema(): array
    {
        return [
            'journal' => ['class' => CashJournal::class],
        ];
    }

    public function getAvailableSnippets(): array
    {
        return [
            [
                'id' => 'cashjournal.month',
                'label' => 'templates.preview.snippet.cashjournal.month',
                'group' => 'Cashjournal',
                'complexity' => 'simple',
                'content' => '[[ journal.cashMonth ]]',
            ],
            [
                'id' => 'cashjournal.year',
                'label' => 'templates.preview.snippet.cashjournal.year',
                'group' => 'Cashjournal',
                'complexity' => 'simple',
                'content' => '[[ journal.cashYear ]]',
            ],
            [
                'id' => 'cashjournal.cash.start',
                'label' => 'templates.preview.snippet.cashjournal.cash_start',
                'group' => 'Cashjournal',
                'complexity' => 'simple',
                'content' => '[[ journal.cashStartF ]]',
            ],
            [
                'id' => 'cashjournal.cash.end',
                'label' => 'templates.preview.snippet.cashjournal.cash_end',
                'group' => 'Cashjournal',
                'complexity' => 'simple',
                'content' => '[[ journal.cashEndF ]]',
            ],
            [
                'id' => 'cashjournal.positions',
                'label' => 'templates.preview.snippet.cashjournal.positions',
                'group' => 'Cashjournal',
                'complexity' => 'easy',
                'content' => "<table style=\"border-collapse: collapse; width: 100%;\" border=\"0\" cellspacing=\"0px\"><tr><th>#</th><th>{{ 'journal.entry.incomes'|trans({}, 'CashJournal') }}</th><th>{{ 'journal.entry.expenses'|trans({}, 'CashJournal') }}</th><th>{{ 'journal.entry.inventory'|trans({}, 'CashJournal') }} €</th><th>{{ 'journal.entry.counteraccount'|trans({}, 'CashJournal') }}</th><th>{{ 'journal.entry.invoicenumber'|trans({}, 'CashJournal') }}</th><th>{{ 'journal.entry.documentnumber'|trans({}, 'CashJournal') }}</th><th>{{ 'journal.entry.date'|trans({}, 'CashJournal') }}</th><th>{{ 'journal.entry.remark'|trans({}, 'CashJournal') }}</th></tr><tr data-repeat=\"journal.cashJournalEntries\" data-repeat-as=\"entry\"><td>[[ loop.index ]]</td><td>[[ entry.incomesF ]]</td><td>[[ entry.expensesF ]]</td><td>[[ entry.inventoryF ]]</td><td>[[ entry.counterAccount ]]</td><td>[[ entry.invoiceNumber ]]</td><td>[[ entry.documentNumberF ]]</td><td>[[ entry.date|date('d.m.Y') ]]</td><td>[[ entry.remark ]]</td></tr></table>",
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
     * Build minimal in-memory sample data when no cash journal exists yet.
     *
     * @param array<string, mixed> $ctx
     *
     * @return array<string, mixed>
     */
    private function buildSampleParams(array $ctx = []): array
    {
        $journal = new CashJournal();
        $journal->setCashMonth((int) date('n'));
        $journal->setCashYear((int) date('Y'));
        $journal->setCashStart('1200.00');
        $journal->setCashEnd('1320.00');

        $entry1 = new CashJournalEntry();
        $entry1->setDocumentNumber(1);
        $entry1->setDate(new \DateTime('today'));
        $entry1->setCounterAccount('8400');
        $entry1->setInvoiceNumber('CJ-1001');
        $entry1->setRemark('Zimmerzahlung');
        $entry1->setIncomes('150.00');
        $entry1->setExpenses('0.00');
        $entry1->setInventory('1350.00');
        $entry1->setCashJournal($journal);
        $journal->addCashJournalEntry($entry1);

        $entry2 = new CashJournalEntry();
        $entry2->setDocumentNumber(2);
        $entry2->setDate(new \DateTime('today'));
        $entry2->setCounterAccount('6800');
        $entry2->setInvoiceNumber('CJ-1002');
        $entry2->setRemark('Einkauf');
        $entry2->setIncomes('0.00');
        $entry2->setExpenses('30.00');
        $entry2->setInventory('1320.00');
        $entry2->setCashJournal($journal);
        $journal->addCashJournalEntry($entry2);

        return array_merge([
            'journal' => $journal,
        ], $ctx);
    }
}
