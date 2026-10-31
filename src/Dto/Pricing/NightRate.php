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

namespace App\Dto\Pricing;

use App\Entity\Price;

/**
 * The room price that applies to one night of a stay: either read live from the price row or
 * taken from the reservation's price promise.
 *
 * The unit price and its meaning (flat, per room, gross) are carried here because a promise may
 * differ from what the row says today, and so is the surcharge a per-room price charges for every
 * person beyond the included ones. VAT rate, description and revenue account are always read from
 * the row.
 */
final readonly class NightRate
{
    /**
     * @param list<PromisedLine>|null $promisedLines        the promised guest lines, null when priced live
     * @param RateAdjustment|null     $adjustment           how price rules changed the row's unit price
     * @param string|null             $extraPersonSurcharge charge per night for every person beyond $includedPersons
     */
    public function __construct(
        public \DateTimeImmutable $night,
        public Price $price,
        public string $unit,
        public bool $includesVat,
        public bool $isFlatPrice,
        public bool $isPerRoom,
        public ?array $promisedLines = null,
        public ?RateAdjustment $adjustment = null,
        public ?string $extraPersonSurcharge = null,
        public int $includedPersons = 0,
    ) {
    }

    public static function live(\DateTimeImmutable $night, Price $price): self
    {
        return new self(
            $night,
            $price,
            PricePromise::money($price->getPrice()),
            (bool) $price->getIncludesVat(),
            (bool) $price->getIsFlatPrice(),
            $price->getIsPerRoom(),
            null,
            null,
            $price->hasExtraPersonSurcharge() ? $price->getExtraPersonSurcharge() : null,
            (int) $price->getNumberOfPersons(),
        );
    }

    public static function promised(\DateTimeImmutable $night, Price $price, PromisedNight $promised): self
    {
        return new self(
            $night,
            $price,
            $promised->unit,
            $promised->includesVat,
            $promised->isFlatPrice,
            $promised->isPerRoom,
            $promised->lines,
            $promised->adjustment,
            $promised->extraPersonSurcharge,
            $promised->includedPersons,
        );
    }

    /**
     * The same night at the unit price the price rules lead to. The rules change the whole room
     * price, so the surcharge moves by the same percentage.
     */
    public function adjusted(string $unit, RateAdjustment $adjustment, ?string $extraPersonSurcharge = null): self
    {
        return new self(
            $this->night,
            $this->price,
            $unit,
            $this->includesVat,
            $this->isFlatPrice,
            $this->isPerRoom,
            $this->promisedLines,
            $adjustment,
            $extraPersonSurcharge ?? $this->extraPersonSurcharge,
            $this->includedPersons,
        );
    }

    /** Persons of a party of $persons billed through the surcharge; 0 without one. */
    public function extraPersons(int $persons): int
    {
        return null === $this->extraPersonSurcharge ? 0 : max(0, $persons - $this->includedPersons);
    }

    /** What the surcharge adds to one night for a party of $persons. */
    public function surchargeTotal(int $persons): float
    {
        return $this->extraPersons($persons) * (float) $this->extraPersonSurcharge;
    }

    public function isPromised(): bool
    {
        return null !== $this->promisedLines;
    }

    /** Consecutive nights with the same key are billed as one invoice position. */
    public function positionKey(): string
    {
        return implode('|', [
            $this->price->getId() ?? spl_object_id($this->price),
            $this->unit,
            $this->includesVat ? 'g' : 'n',
            $this->isFlatPrice ? 'f' : ($this->isPerRoom ? 'r' : 'p'),
            $this->extraPersonSurcharge ?? '-',
        ]);
    }
}
