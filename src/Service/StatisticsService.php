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

use App\Entity\Invoice;
use Doctrine\ORM\EntityManagerInterface;

class StatisticsService
{
    private $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /**
     * Calculates the turnover for the given period based on invoices.
     */
    private function loadTurnover(InvoiceService $is, \DateTimeInterface $start, \DateTimeInterface $end, array $status): float
    {
        $turnover = 0.0;
        $invoices = $this->em->getRepository(Invoice::class)->getInvoicesForYear($start, $end, $status);
        /* @var $invoice Invoice */
        foreach ($invoices as $invoice) {
            $vatSums = [];
            $brutto = 0;
            $netto = 0;
            $apartmentTotal = 0;
            $miscTotal = 0;
            $is->calculateSums(
                $invoice->getAppartments(),
                $invoice->getPositions(),
                $vatSums,
                $brutto,
                $netto,
                $apartmentTotal,
                $miscTotal
            );
            $turnover += $brutto;
        }

        return $turnover;
    }

    /**
     * Calculate turnover for a single month using the invoice status filter.
     */
    public function loadTurnoverForSingleMonth(InvoiceService $is, int $year, int $month, array $status): float
    {
        $start = new \DateTime($year.'-'.$month.'-01');
        $end = new \DateTime($year.'-'.$month.'-'.$start->format('t'));

        return $this->loadTurnover($is, $start, $end, $status);
    }
}
