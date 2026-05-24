<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\BankImport;

use App\Dto\BookingJournal\BankImport\ImportState;
use App\Entity\Invoice;
use App\Repository\InvoiceRepository;
use App\Service\BookingJournal\AccountingSettingsService;
use App\Service\InvoiceService;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * Maps a {@see ImportState} line to an existing {@see Invoice}, if any.
 *
 * Two-step strategy:
 *  1. Extract candidate numbers from the line's purpose text via the
 *     {@see InvoiceNumberPatternBuilder} (driven by the user's example
 *     invoice numbers).
 *  2. Tie-break on amount when several candidates resolve to invoices.
 *
 * The matcher only annotates the line; nothing is committed yet.
 */
final class InvoiceMatcher
{
    public function __construct(
        private readonly AccountingSettingsService $settingsService,
        private readonly InvoiceNumberPatternBuilder $patternBuilder,
        private readonly InvoiceRepository $invoiceRepo,
        private readonly InvoiceService $invoiceService,
    ) {
    }

    public function annotate(ImportState $state): void
    {
        $samples = $this->settingsService->getSettings()->getInvoiceNumberSamples();
        $matcher = $this->patternBuilder->buildFromSamples($samples);

        if ($matcher->isEmpty()) {
            return;
        }

        foreach ($state->lines as &$line) {
            // Skip lines that already have a stronger signal (rule-applied,
            // ignored, duplicates) — invoice matching is just a hint.
            if (true === ($line['isDuplicate'] ?? false) || true === ($line['isIgnored'] ?? false)) {
                continue;
            }

            $invoice = $this->matchPurpose((string) ($line['purpose'] ?? ''), (string) ($line['amount'] ?? ''), $matcher);
            if (null === $invoice) {
                continue;
            }

            $line['matchedInvoiceId'] = $invoice->getId();
            $line['matchedInvoiceNumber'] = $invoice->getNumber();
            $line['matchedInvoiceAmountMatches'] = $this->amountsMatch($invoice, abs((float) ($line['amount'] ?? '0')));

            if (true === $line['matchedInvoiceAmountMatches']
                && ((float) ($line['amount'] ?? 0)) >= 0.0
                && null !== ($line['userDebitAccountId'] ?? null)
            ) {
                $line['status'] = ImportState::LINE_STATUS_READY;
            }
        }
        unset($line);
    }

    /**
     * Returns the best matching invoice for the given purpose text + line amount,
     * or null if none of the candidates resolves to an actual invoice.
     */
    public function matchPurpose(string $purpose, string $lineAmount, CompiledMatcher $matcher): ?Invoice
    {
        $candidates = $matcher->extractCandidates($purpose);
        if ([] === $candidates) {
            return null;
        }

        $invoices = $this->invoiceRepo->findByNumbers($candidates);
        if ([] === $invoices) {
            return null;
        }

        if (1 === count($invoices)) {
            return $invoices[0];
        }

        // Several invoices matched — prefer the one whose total equals the
        // line amount (absolute, since incoming/outgoing varies).
        $target = abs((float) $lineAmount);

        $byNumber = [];
        foreach ($invoices as $invoice) {
            $byNumber[$invoice->getNumber()] = $invoice;
        }

        // Walk candidates in source order so the first textual match wins
        // when no amount disambiguates.
        $bestExact = null;
        foreach ($candidates as $number) {
            $invoice = $byNumber[$number] ?? null;
            if (null === $invoice) {
                continue;
            }

            if ($this->amountsMatch($invoice, $target)) {
                return $invoice; // exact amount match — stop here.
            }

            $bestExact ??= $invoice;
        }

        return $bestExact;
    }

    public function amountsMatch(Invoice $invoice, float $target): bool
    {
        if (0.0 === $target) {
            return false;
        }

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

        return abs($brutto - $target) < 0.01;
    }
}
