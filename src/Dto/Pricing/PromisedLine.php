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

use App\Entity\Enum\ModifierType;

/**
 * One guest category of a promised night: how many guests of it pay which per-head price, and
 * the modifier that produced the price, if any.
 */
final readonly class PromisedLine
{
    public function __construct(
        public int $categoryId,
        public int $count,
        public string $unit,
        public ?GuestAdjustment $adjustment = null,
    ) {
    }

    /** @return array{0: int, 1: int, 2: string, 3?: string, 4?: string} */
    public function toArray(): array
    {
        $row = [$this->categoryId, $this->count, $this->unit];
        if (null !== $this->adjustment) {
            $row[] = $this->adjustment->type->value;
            $row[] = PricePromise::money($this->adjustment->value);
        }

        return $row;
    }

    public static function fromArray(mixed $row): ?self
    {
        if (!is_array($row) || !is_int($row[0] ?? null) || !is_int($row[1] ?? null) || !is_string($row[2] ?? null)) {
            return null;
        }
        $adjustment = null;
        if (isset($row[3], $row[4])) {
            $type = is_string($row[3]) ? ModifierType::tryFrom($row[3]) : null;
            if (null === $type || !is_numeric($row[4])) {
                return null;
            }
            $adjustment = new GuestAdjustment($type, (float) $row[4]);
        }

        return new self($row[0], $row[1], $row[2], $adjustment);
    }
}
