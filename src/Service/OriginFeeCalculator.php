<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\OriginFee;
use App\Dto\OriginFeeBreakdown;
use App\Entity\Invoice;
use App\Entity\InvoicePosition;
use App\Entity\Reservation;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

/**
 * What a portal charges the house for a booking: its commission and its payment
 * fee, each as a percentage of its own base.
 *
 * The one place that answers this. Both the figures an invoice shows the guest
 * (InvoiceService::buildTemplateRenderParams) and the deduction the journal
 * books (CreatePercentageEntryAction) come from here, so what the guest is told
 * and what the accounts record cannot drift apart. They used to be worked out
 * separately, and the deduction's base was a workflow setting the render path
 * had no way of reading.
 *
 * Neither base is a rule about invoices; both are read off what the invoice
 * records about itself. Every position says whether a portal brokered it and
 * whether commission is charged on it (see InvoicePosition), and the reservation
 * says who collected the payment. So:
 *
 * - Commission is taken on the stay plus every position marked commissionable.
 *   A separately billed tourist tax is not one - see
 *   InvoicePosition::$commissionable for what that rests on - and neither is
 *   what the house sells on site.
 * - The payment fee is taken on what the portal actually processed: the brokered
 *   positions, plus the stay where the portal collected the payment for it. A
 *   booking the house was paid for directly leaves the portal nothing to charge
 *   a payment fee on, however much it brokered.
 *
 * The stay itself carries no such flags. It needs none: it is the thing the
 * portal brokered, and commission on it is the whole point of the arrangement.
 */
class OriginFeeCalculator
{
    public function __construct(
        private readonly InvoiceSumCalculator $sums,
    ) {
    }

    public function calculate(Invoice $invoice): OriginFeeBreakdown
    {
        $shown = $this->reservationTheFiguresBelongTo($invoice);

        return new OriginFeeBreakdown(
            $shown?->getReservationOrigin()?->getName(),
            $this->fee(
                $invoice,
                $shown,
                $this->baseOf(
                    $invoice,
                    static fn (InvoicePosition $p): bool => $p->isCommissionable(),
                    includeStay: true,
                ),
                static fn (Reservation $r): ?string => $r->getCommissionPercent()
                    ?? $r->getReservationOrigin()?->getCommissionPercent(),
            ),
            $this->paymentFee($invoice, $shown),
        );
    }

    /**
     * The gross total of an invoice, optionally counting only what a commission
     * would be charged on.
     *
     * Public for the one caller that picks its own base: a workflow booking a
     * percentage somebody typed in, where nothing about the booking says what
     * the percentage is of and only the config can answer.
     */
    public function grossTotal(Invoice $invoice, bool $commissionableOnly = false): float
    {
        return $this->baseOf(
            $invoice,
            static fn (InvoicePosition $p): bool => !$commissionableOnly || $p->isCommissionable(),
            includeStay: true,
        );
    }

    /**
     * The sum of the parts a fee is charged on.
     *
     * Positions are dropped before the sum rather than subtracted afterwards, so
     * the per-VAT-rate rounding stays the one the remaining parts produce on
     * their own.
     *
     * @param callable(InvoicePosition): bool $keep
     */
    private function baseOf(Invoice $invoice, callable $keep, bool $includeStay): float
    {
        /** @var Collection<int, InvoicePosition> $positions */
        $positions = ($invoice->getPositions() ?? new ArrayCollection())->filter($keep);

        $stay = $includeStay
            ? $invoice->getAppartments() ?? new ArrayCollection()
            : new ArrayCollection();

        return $this->sums->grossTotal($stay, $positions);
    }

    /**
     * The payment fee, which unlike the commission depends on who took the
     * money: a portal charges it for processing a payment, so a stay the house
     * was paid for directly carries none.
     *
     * Where the invoice's reservations were settled differently the stay has no
     * single answer, and the fee is marked as having no single base rather than
     * quietly leaving the whole stay out. Dropping it silently books too little
     * and reads as if that were the figure.
     */
    private function paymentFee(Invoice $invoice, ?Reservation $shown): OriginFee
    {
        $collectedByPortal = $this->portalCollectedThePayment($invoice);

        return $this->fee(
            $invoice,
            $shown,
            $this->baseOf(
                $invoice,
                static fn (InvoicePosition $p): bool => $p->isBrokered(),
                // Undecided counts as not collected here: the base is only used
                // where the caller has accepted it, and a figure nobody may use
                // is better too small than too large.
                includeStay: true === $collectedByPortal,
            ),
            static fn (Reservation $r): ?string => $r->getPaymentFeePercent()
                ?? $r->getReservationOrigin()?->getPaymentFeePercent(),
            baseIsOne: null !== $collectedByPortal,
        );
    }

    /**
     * Whether the portal took the money for the stay itself - null where the
     * invoice's reservations disagree about it.
     *
     * Every reservation has to say so, and one without an origin never does. An
     * invoice mixing a portal booking with a direct one has no single answer,
     * and an invoice carries no attribution of its lines to reservations to
     * split the stay along.
     *
     * What was recorded on the reservation wins over what its origin says today,
     * as with the rates: a portal that starts collecting payments must not
     * rewrite how older bookings were settled. Where nothing was recorded the
     * origin answers, and where there is no origin either, nobody but the house
     * took anything.
     */
    private function portalCollectedThePayment(Invoice $invoice): ?bool
    {
        $reservations = $invoice->getReservations() ?? new ArrayCollection();
        if (0 === count($reservations)) {
            return false;
        }

        $answers = [];
        foreach ($reservations as $reservation) {
            $collection = $reservation->getPaymentCollection()
                ?? $reservation->getReservationOrigin()?->getPaymentCollection();

            $answers[] = null !== $collection && $collection->isPortal();
        }

        $portal = in_array(true, $answers, true);
        $property = in_array(false, $answers, true);

        return $portal && $property ? null : $portal;
    }

    /**
     * One fee, at the rate that holds for the invoice.
     *
     * Where every reservation agrees, that agreed rate is it - including the
     * case of an invoice with no reservations at all, which yields nothing to
     * book. Where they disagree there is no such rate, and the fee says so
     * through OriginFee::isAgreedUpon(); what it carries then is the rate of
     * the reservation the figures belong to, which no caller may state as the
     * invoice's own but which keeps the amount from being an arbitrary zero.
     *
     * @param callable(Reservation): ?string $rateOf
     */
    private function fee(Invoice $invoice, ?Reservation $shown, float $base, callable $rateOf, bool $baseIsOne = true): OriginFee
    {
        $rates = [];
        foreach ($invoice->getReservations() ?? [] as $reservation) {
            $rate = $this->toPercent($rateOf($reservation));
            // Keyed by the formatted figure: it doubles as the label in a log
            // line and keeps "12", "12.00" and 12.0 from counting as three
            // rates.
            $rates[number_format($rate, 2, ',', '.').' %'] = $rate;
        }

        $percent = 1 === count($rates)
            ? reset($rates)
            : (null !== $shown ? $this->toPercent($rateOf($shown)) : 0.0);

        return new OriginFee($percent, $base, $rates, $baseIsOne);
    }

    /**
     * The reservation whose portal and rates the invoice shows.
     *
     * The first one that came through a portal charging anything. An invoice can
     * hold several, which is a disagreement both callers stop at - but the
     * portal is still named, so an invoice can say whose fees it cannot state
     * rather than staying silent about them altogether.
     *
     * A reservation whose rates are both zero is passed over on purpose: an
     * origin exists for direct bookings too, and naming one that costs nothing
     * would put a portal on an invoice that has no surcharge to explain.
     */
    private function reservationTheFiguresBelongTo(Invoice $invoice): ?Reservation
    {
        foreach ($invoice->getReservations() ?? [] as $reservation) {
            $origin = $reservation->getReservationOrigin();
            if (null === $origin) {
                continue;
            }

            $commission = $this->toPercent($reservation->getCommissionPercent() ?? $origin->getCommissionPercent());
            $paymentFee = $this->toPercent($reservation->getPaymentFeePercent() ?? $origin->getPaymentFeePercent());

            if ($commission > 0.0 || $paymentFee > 0.0) {
                return $reservation;
            }
        }

        return null;
    }

    /** Reads a percentage as it may have been typed or stored, commas included. */
    private function toPercent(?string $raw): float
    {
        return (float) str_replace(',', '.', trim((string) $raw));
    }
}
