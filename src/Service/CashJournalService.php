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
use App\Entity\Template;
use App\Interfaces\ITemplateRenderer;
use Doctrine\ORM\EntityManagerInterface;

class CashJournalService implements ITemplateRenderer
{
    private $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
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
        /* @var $entry \Pensionsverwaltung\Database\Entity\CashJournalEntry */
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

    public function getRenderParams(Template $template, mixed $param)
    {
        $journal = $this->em->getRepository(CashJournal::class)->find($param);

        $params = [
                'journal' => $journal,
            ];

        return $params;
    }
}
