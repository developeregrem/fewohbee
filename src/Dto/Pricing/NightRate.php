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
 * differ from what the row says today. VAT rate, description and revenue account are always read
 * from the row.
 */
final readonly class NightRate
{
    /**
     * @param list<PromisedLine>|null $promisedLines the promised guest lines, null when priced live
     * @param RateAdjustment|null     $adjustment    how price rules changed the row's unit price
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
        );
    }

    /** The same night at the unit price the price rules lead to. */
    public function adjusted(string $unit, RateAdjustment $adjustment): self
    {
        return new self($this->night, $this->price, $unit, $this->includesVat, $this->isFlatPrice, $this->isPerRoom, $this->promisedLines, $adjustment);
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
        ]);
    }
}
