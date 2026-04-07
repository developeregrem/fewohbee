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

namespace App\Service;

use App\Entity\CashJournal;
use App\Entity\CashJournalEntry;
use App\Entity\Invoice;
use App\Entity\Template;
use App\Service\InvoiceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CashJournalService
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly InvoiceService $invoiceService, private readonly TranslatorInterface $translator)
    {
    }

    public function getJournalFromForm($request, $id = 'new'): CashJournal
    {
        $journal = new CashJournal();

        if ('new' !== $id) {
            $journal = $this->em->getRepository(CashJournal::class)->find($id);
        }

        $journal->setCashYear($request->request->get('year'));
        $journal->setCashMonth($request->request->get('month'));
        $journal->setCashStart(str_replace(',', '.', $request->request->get('cashStart')));
        $journal->setCashEnd(str_replace(',', '.', $request->request->get('cashEnd')));

        if (0 == strlen($journal->getCashEnd())) {
            $journal->setCashEnd(0.00);
        }

        return $journal;
    }

    public function getJournalEntryFromForm($request, $id = 'new')
    {
        $entry = new CashJournalEntry();

        if ('new' !== $id) {
            $entry = $this->em->getRepository(CashJournalEntry::class)->find($id);
        }

        $entry->setIncomes(str_replace(',', '.', $request->request->get('incomes')));
        $entry->setExpenses(str_replace(',', '.', $request->request->get('expenses')));
        $entry->setCounterAccount($request->request->get('counterAccount'));
        $entry->setInvoiceNumber($request->request->get('invoiceNumber'));
        $entry->setDocumentNumber((int) $request->request->get('documentNumber'));
        $entry->setDate($request->request->get('date') ? new \DateTime($request->request->get('date')) : null);
        $entry->setRemark($request->request->get('remark'));

        if (0 == strlen($entry->getExpenses())) {
            $entry->setExpenses(0.00);
        }
        if (0 == strlen($entry->getIncomes())) {
            $entry->setIncomes(0.00);
        }

        return $entry;
    }

    public function calcaulateInventory(CashJournal &$journal): void
    {
        $inventory = $journal->getCashStart();
        foreach ($journal->getCashJournalEntries() as $entry) {
            if (null != $entry->getIncomes()) {
                $inventory += $entry->getIncomes();
            }
            if (null != $entry->getExpenses()) {
                $inventory -= $entry->getExpenses();
            }
            $entry->setInventory(round($inventory, 2));
            $this->em->persist($entry);
        }
        $this->em->flush();
    }

    public function calcaulateDocumentNumber(CashJournal &$journal): void
    {
        $docNumber = $this->em->getRepository(CashJournalEntry::class)->getMinDocumentNumber($journal);
        /* @var $entry \Pensionsverwaltung\Database\Entity\CashJournalEntry */
        foreach ($journal->getCashJournalEntries() as $entry) {
            $entry->setDocumentNumber($docNumber++);
            $this->em->persist($entry);
        }
        $this->em->flush();
    }

    public function recalculateCashEnd(CashJournal $journal)
    {
        // first, calculate the inventory
        $this->calcaulateInventory($journal);
        $this->calcaulateDocumentNumber($journal);
        // now get the last entry, which holds the value for cash end
        $entries = $journal->getCashJournalEntries();
        $count = $entries->count();
        if ($count > 0) {
            $lastInventory = $entries->get($count - 1)->getInventory();
            $journal->setCashEnd($lastInventory);
        } else {
            // no entry -> cash end is equal to start
            $journal->setCashEnd($journal->getCashStart());
        }
        $this->em->persist($journal);
        $this->em->flush();

        return $journal;
    }

    /**
     * Creates a CashJournalEntry from an Invoice.
     *
     * The CashJournal for the invoice's year/month is looked up and created automatically if missing.
     * Throws \RuntimeException if the target journal is closed.
     */
    public function createEntryFromInvoice(Invoice $invoice, ?string $remark = null): CashJournalEntry
    {
        $invoiceDate = $invoice->getDate();
        $year = (int) $invoiceDate->format('Y');
        $month = (int) $invoiceDate->format('n');

        $journal = $this->em->getRepository(CashJournal::class)->findByYearAndMonth($year, $month);

        if (null === $journal) {
            $journal = new CashJournal();
            $journal->setCashYear($year);
            $journal->setCashMonth($month);

            $youngestJournal = $this->em->getRepository(CashJournal::class)->getYoungestJournal();
            $cashStart = ($youngestJournal instanceof CashJournal) ? $youngestJournal->getCashEnd() : 0.0;
            $journal->setCashStart($cashStart);
            $journal->setCashEnd($cashStart);

            $this->em->persist($journal);
            $this->em->flush();
        }

        if ($journal->getIsClosed()) {
            throw new \RuntimeException(
                $this->translator->trans('journal.error.journal.closed', [
                    '%month%' => $month,
                    '%year%' => $year,
                ])
            );
        }

        $vats = [];
        $brutto = 0.0;
        $netto = 0.0;
        $appartmentTotal = 0.0;
        $miscTotal = 0.0;
        $this->invoiceService->calculateSums(
            $invoice->getAppartments(),
            $invoice->getPositions(),
            $vats,
            $brutto,
            $netto,
            $appartmentTotal,
            $miscTotal
        );

        $nextDocNumber = $this->em->getRepository(CashJournalEntry::class)->getLastDocumentNumber($journal) + 1;

        $entry = new CashJournalEntry();
        $entry->setDate(\DateTime::createFromInterface($invoiceDate));
        $entry->setDocumentNumber($nextDocNumber);
        $entry->setInvoiceNumber($invoice->getNumber());
        $entry->setIncomes(round($brutto, 2));
        $entry->setExpenses(0.0);
        $entry->setRemark($remark ?? '');
        $entry->setCashJournal($journal);
        $journal->addCashJournalEntry($entry);

        $this->em->persist($entry);
        $this->em->flush();

        $this->recalculateCashEnd($journal);

        return $entry;
    }

    /**
     * Build all cash journal variables required by template rendering.
     */
    public function buildTemplateRenderParams(Template $template, mixed $param): array
    {
        $journal = $this->em->getRepository(CashJournal::class)->find($param);

        $params = [
            'journal' => $journal,
        ];

        return $params;
    }
}
