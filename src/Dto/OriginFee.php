<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One fee a portal charges for a booking - a commission or a payment fee -
 * with the amount it comes to and everything needed to judge that amount.
 *
 * The rates found on the invoice come along so that a caller refusing such an
 * invoice can name them: the journal says in its log which rates it found
 * rather than only that it gave up. Whether an amount can be stated at all is
 * decided here, by isSettled(), so that the journal and the invoice cannot
 * answer it differently.
 */
final readonly class OriginFee
{
    /** Percent of base, rounded to cents. Derived rather than passed, so no
     *  caller can hand on an amount its own two figures do not add up to. */
    public float $amount;

    /**
     * @param float                $percent     the rate this fee is charged at
     * @param float                $base        the amount the percentage is taken of
     * @param array<string, float> $rates       every distinct rate the invoice's reservations
     *                                          carry for this fee, keyed by its formatted form
     *                                          so a caller can name them in a message. Empty
     *                                          where the rate did not come from the booking
     *                                          at all but was typed into a workflow
     * @param bool                 $baseIsOne   false where the invoice does not yield a single
     *                                          amount this fee is charged on - see hasOneBase()
     */
    public function __construct(
        public float $percent,
        public float $base,
        public array $rates = [],
        public bool $baseIsOne = true,
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

    /**
     * Whether the invoice says what this fee is charged on.
     *
     * It does not when its reservations were settled differently - one paid
     * through the portal, another directly to the house. The stay is then
     * charged a payment fee for one and none for the other, and an invoice
     * carries no attribution of its lines to reservations to split it along.
     */
    public function hasOneBase(): bool
    {
        return $this->baseIsOne;
    }

    /**
     * Whether the amount can be stated at all, rather than guessed.
     *
     * Both callers stop here, differently: the journal refuses to book and says
     * why, while an invoice shown to a guest leaves the figure out instead of
     * printing one that may be wrong.
     */
    public function isSettled(): bool
    {
        return $this->isAgreedUpon() && $this->hasOneBase();
    }

    /** @return string[] the rates as they read in a message, e.g. "12,00 %" */
    public function rateLabels(): array
    {
        return array_keys($this->rates);
    }
}
