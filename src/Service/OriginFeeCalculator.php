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
 * The bases differ because the fees are charged on different things:
 *
 * - Commission is taken on what the house earns. Booking.com exempts the
 *   tourist tax from it as long as the tax is entered on their side as a
 *   separate local tax or as payable on arrival, which is how a tax that shows
 *   up as a position of its own here is set up. Tourist tax therefore drops out
 *   of the base.
 * - The payment fee is taken on what the portal actually processed, tourist tax
 *   included when the portal collected it.
 *
 * That second base is the rough one for now: whether the portal collected the
 * payment at all is not recorded yet, so the full gross stands in for it. It
 * overstates the fee for a stay whose tourist tax the house collects on
 * arrival. The figure is in the workflow log (%base% in
 * workflow.log.percentage_entry_created), so it can be checked against the
 * portal's own statement and corrected. Once a position can say whether the
 * portal brokered it, both bases are read off the positions instead and this is
 * the only place that changes.
 */
class OriginFeeCalculator
{
    /**
     * Position group the tourist-tax positions carry, see
     * InvoicePosition::$positionGroup and
     * InvoiceService::makeTouristTaxPosition().
     */
    private const POSITION_GROUP_TOURIST_TAX = 'tourist_tax';

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
                $this->grossTotal($invoice, excludeTouristTax: true),
                static fn (Reservation $r): ?string => $r->getCommissionPercent()
                    ?? $r->getReservationOrigin()?->getCommissionPercent(),
            ),
            $this->fee(
                $invoice,
                $shown,
                $this->grossTotal($invoice, excludeTouristTax: false),
                static fn (Reservation $r): ?string => $r->getPaymentFeePercent()
                    ?? $r->getReservationOrigin()?->getPaymentFeePercent(),
            ),
        );
    }

    /**
     * The gross total of an invoice, optionally without its tourist tax.
     *
     * Public for the one caller that still picks its own base: a workflow
     * booking a percentage somebody typed in, where nothing but the config says
     * what the percentage is of.
     */
    public function grossTotal(Invoice $invoice, bool $excludeTouristTax): float
    {
        $positions = $invoice->getPositions() ?? new ArrayCollection();

        if ($excludeTouristTax) {
            // Dropped before the sum rather than subtracted afterwards, so the
            // per-VAT-rate rounding inside the sum stays the one the remaining
            // positions produce on their own.
            $positions = $positions->filter(
                static fn (InvoicePosition $position): bool => self::POSITION_GROUP_TOURIST_TAX !== $position->getPositionGroup()
            );
        }

        /** @var Collection<int, InvoicePosition> $positions */
        return $this->sums->grossTotal($invoice->getAppartments() ?? new ArrayCollection(), $positions);
    }

    /**
     * One fee, at the rate that holds for the invoice.
     *
     * Which rate that is has two answers, and both are needed. Where every
     * reservation agrees, that agreed rate is it - including the case of an
     * invoice with no reservations at all, which yields nothing to book. Where
     * they disagree, the rate is the one of the reservation the figures are
     * shown for; the journal refuses such an invoice anyway (see
     * OriginFee::isAgreedUpon), while the guest is shown a figure rather than a
     * blank.
     *
     * @param callable(Reservation): ?string $rateOf
     */
    private function fee(Invoice $invoice, ?Reservation $shown, float $base, callable $rateOf): OriginFee
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

        return new OriginFee($percent, $base, $rates);
    }

    /**
     * The reservation whose portal and rates the invoice shows.
     *
     * The first one that came through a portal charging anything. An invoice can
     * hold several - which is a disagreement the journal stops at, but a note to
     * the guest names the first rather than staying silent about a surcharge
     * they did pay.
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
