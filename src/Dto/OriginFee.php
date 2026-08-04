<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One fee a portal charges for a booking - a commission or a payment fee -
 * with the amount it comes to and everything needed to judge that amount.
 *
 * The rates found on the invoice come along because the two callers disagree on
 * what to do when they disagree: the journal refuses to book an invoice whose
 * reservations were taken under different rates, while an invoice shown to a
 * guest names the first of them rather than nothing at all. Deciding that here
 * would force one of those answers onto both.
 */
final readonly class OriginFee
{
    /** Percent of base, rounded to cents. Derived rather than passed, so no
     *  caller can hand on an amount its own two figures do not add up to. */
    public float $amount;

    /**
     * @param float                $percent the rate this fee is charged at
     * @param float                $base    the amount the percentage is taken of
     * @param array<string, float> $rates   every distinct rate the invoice's reservations
     *                                      carry for this fee, keyed by its formatted form
     *                                      so a caller can name them in a message. Empty
     *                                      where the rate did not come from the booking
     *                                      at all but was typed into a workflow
     */
    public function __construct(
        public float $percent,
        public float $base,
        public array $rates = [],
    ) {
        $this->amount = round($base * $percent / 100.0, 2);
    }

    /**
     * Whether all reservations on the invoice were taken under one rate. An
     * invoice without reservations agrees trivially - it has nothing to
     * disagree about, and its fee is zero anyway.
     */
    public function isAgreedUpon(): bool
    {
        return count($this->rates) <= 1;
    }

    /** @return string[] the rates as they read in a message, e.g. "12,00 %" */
    public function rateLabels(): array
    {
        return array_keys($this->rates);
    }
}
