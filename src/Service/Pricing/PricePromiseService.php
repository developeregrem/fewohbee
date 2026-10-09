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

namespace App\Service\Pricing;

use App\Dto\PriceBreakdown;
use App\Dto\Pricing\PricePromise;
use App\Dto\Pricing\PromisedExtra;
use App\Dto\Pricing\PromisedLine;
use App\Dto\Pricing\PromisedNight;
use App\Entity\Reservation;
use App\Repository\ReservationRepository;
use App\Service\InvoiceService;
use App\Service\PriceService;
use Doctrine\Common\Collections\ArrayCollection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Keeps the price promise of a reservation (see PricePromise) in line with the reservation.
 *
 * Every path that creates a reservation or changes what its price depends on - dates, room,
 * guests, origin, extras - calls reconcile() before flushing. A path that does not is still safe:
 * a promise made for a different room category, origin or occupancy is ignored when pricing, and
 * nights it does not cover are priced from the current price list as before.
 */
class PricePromiseService
{
    public function __construct(
        private readonly PriceService $priceService,
        private readonly InvoiceService $invoiceService,
        private readonly ClockInterface $clock,
        private readonly ReservationRepository $reservations,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Brings the promise in line with the reservation as it is now. Nights that are still part of
     * the stay keep their promised price as long as room category, subsidiary, origin and guests
     * are unchanged; all other nights are priced from the current price list. Booked extras keep
     * their promised unit price, newly booked ones get today's. With $repriceAll everything is
     * priced from the current price list.
     *
     * A saved booking without any promise - made before promises existed, or invoiced back then -
     * has been priced from the plain price list. Its first promise keeps that price: price rules
     * and day prices are for new bookings, and for "today's prices" only on request ($repriceAll).
     *
     * Idempotent: calling it again without a change to the reservation changes nothing.
     */
    public function reconcile(Reservation $reservation, bool $repriceAll = false): void
    {
        $context = PricePromise::contextKey($reservation);
        // Pricing needs a room and an origin (the price rows are bound to origins). A reservation
        // without either gets an empty promise: nothing is promised, the price list answers.
        if (null === $reservation->getAppartment() || null === $reservation->getReservationOrigin()) {
            $reservation->setPricePromise($this->emptyPromise($context));

            return;
        }

        $old = $repriceAll ? null : $this->priceService->promiseOf($reservation);
        $keepNights = null !== $old && $old->context === $context;
        $existing = null !== $old ? $this->priceService->findPricesById($old->priceIds()) : [];

        $applyPriceRules = $repriceAll || null === $reservation->getId() || null !== $reservation->getPricePromise();
        $rates = $this->priceService->getNightRates($reservation, ignorePromise: true, applyPriceRules: $applyPriceRules);
        $breakdowns = $this->priceService->getPriceBreakdownForReservation($reservation, ignorePromise: true, applyPriceRules: $applyPriceRules);

        $nights = [];
        $keptAny = false;
        foreach ($rates as $i => $rate) {
            $night = \DateTimeImmutable::createFromInterface($reservation->getStartDate())->setTime(0, 0)->modify('+'.$i.' day');
            $kept = $keepNights ? $old->night($night) : null;
            if (null !== $kept && isset($existing[$kept->priceId])) {
                $nights[$night->format('Y-m-d')] = $kept;
                $keptAny = true;
                continue;
            }
            if (null !== $rate) {
                $nights[$night->format('Y-m-d')] = PromisedNight::fromRate($rate, $this->linesOf($breakdowns[$i] ?? null));
            }
        }

        $extras = [];
        foreach ($reservation->getPrices() as $price) {
            $id = (int) $price->getId();
            $kept = null !== $old && isset($existing[$id]) ? $old->extra($id) : null;
            if (null !== $kept) {
                $extras[$id] = $kept;
                $keptAny = true;
            } elseif ($id > 0 && $price->getActive()) {
                // Inactive extras are not billed without a promise, so none is made for them.
                $extras[$id] = new PromisedExtra($id, PricePromise::money($price->getPrice()), (bool) $price->getIncludesVat());
            }
        }

        // Also stored when nothing could be priced, so the reservation counts as handled; such a
        // promise covers no night and the price list keeps answering for it.
        $promise = new PricePromise(
            $keptAny ? $old->promisedOn : $this->clock->now()->format('Y-m-d'),
            $context,
            $nights,
            $extras,
        );
        $reservation->setPricePromise($promise->toArray());
    }

    /**
     * Gives bookings made before price promises existed today's price as their promise: those
     * without an active (not canceled) invoice and without a promise whose stay ended at most a
     * year ago. Call it right before the price list changes - until then they are priced from the
     * unchanged list anyway.
     * Once all are handled it costs a single query.
     */
    public function promiseOpenReservations(): void
    {
        $endFrom = $this->clock->now()->setTime(0, 0)->modify('-12 months');
        foreach ($this->reservations->findIdsWithoutPricePromise($endFrom) as $id) {
            $reservation = $this->reservations->find($id);
            if (!$reservation instanceof Reservation) {
                continue;
            }
            try {
                $this->reconcile($reservation);
                $promise = $reservation->getPricePromise();
            } catch (\Throwable $e) {
                // A booking that cannot be priced (e.g. an implausible period) keeps being priced
                // from the price list; the empty promise only marks it as handled.
                $this->logger->warning('Could not promise a price to reservation {id}: {message}', ['id' => $id, 'message' => $e->getMessage()]);
                $promise = null;
            }
            // Stored by a direct update, bypassing the unit of work: the booking itself did not
            // change, so it stays out of the change log and is not written again by the caller's flush.
            $reservation->setPricePromise(null);
            $this->reservations->storePricePromise($id, $promise ?? $this->emptyPromise(PricePromise::contextKey($reservation)));
        }
    }

    /**
     * How many bookings without an active invoice were promised a price from this row. Checked in
     * PHP: the promise is JSON and DQL has no JSON functions; it is only asked when a price is
     * deleted.
     */
    public function countOpenPromisesUsing(int $priceId): int
    {
        return count(array_filter(
            $this->reservations->findOpenPricePromises(),
            static fn (array $stored): bool => in_array($priceId, PricePromise::fromArray($stored)?->priceIds() ?? [], true),
        ));
    }

    /**
     * What the reservation costs with its current promise and what it would cost priced entirely
     * from the current price list. The reservation is left exactly as it was.
     *
     * @return array{promised: float, current: float}
     */
    public function compareWithCurrentPrices(Reservation $reservation): array
    {
        $promised = $this->total($reservation);
        $stored = $reservation->getPricePromise();
        try {
            $this->reconcile($reservation, repriceAll: true);
            $current = $this->total($reservation);
        } finally {
            $reservation->setPricePromise($stored);
        }

        return ['promised' => $promised, 'current' => $current];
    }

    /**
     * The gross amount the reservation adds to an invoice - room nights, guest adjustments, extras
     * and tourist tax - matching the total shown on the reservation. The tourist tax itself is
     * never promised, but a percentage tax follows the room price.
     */
    public function total(Reservation $reservation): float
    {
        $vats = [];
        $brutto = 0.0;
        $netto = 0.0;
        $apartmentTotal = 0.0;
        $miscTotal = 0.0;
        $this->invoiceService->calculateSums(
            new ArrayCollection($this->invoiceService->buildAppartmentPositions($reservation)),
            new ArrayCollection(array_merge(
                $this->invoiceService->buildApartmentModifierPositions([$reservation]),
                $this->invoiceService->buildMiscPositions([$reservation], true),
                $this->invoiceService->buildTouristTaxPositions([$reservation]),
            )),
            $vats,
            $brutto,
            $netto,
            $apartmentTotal,
            $miscTotal,
        );

        return round($brutto, 2);
    }

    /**
     * A promise that covers nothing, for a reservation that could not be priced. It marks the
     * reservation as handled; its price keeps coming from the price list.
     *
     * @return array<string, mixed>
     */
    public function emptyPromise(string $context): array
    {
        return (new PricePromise($this->clock->now()->format('Y-m-d'), $context, [], []))->toArray();
    }

    /** @return list<PromisedLine> */
    private function linesOf(?PriceBreakdown $breakdown): array
    {
        if (null === $breakdown) {
            return [];
        }
        $lines = [];
        foreach ($breakdown->lines as $line) {
            $lines[] = new PromisedLine(
                (int) $line->category->getId(),
                $line->count,
                PricePromise::money($line->unitPrice),
                $line->adjustment,
            );
        }

        return $lines;
    }
}
