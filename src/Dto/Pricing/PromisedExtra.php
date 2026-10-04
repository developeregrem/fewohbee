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

/**
 * The unit price promised for a booked extra. The quantity is not promised: it follows the stay
 * (nights, persons) exactly as for extras without a promise.
 */
final readonly class PromisedExtra
{
    public function __construct(
        public int $priceId,
        public string $unit,
        public bool $includesVat,
    ) {
    }

    /** @return array{p: int, u: string, g: bool} */
    public function toArray(): array
    {
        return ['p' => $this->priceId, 'u' => $this->unit, 'g' => $this->includesVat];
    }

    public static function fromArray(mixed $row): ?self
    {
        if (!is_array($row) || !is_int($row['p'] ?? null) || !is_string($row['u'] ?? null) || !is_bool($row['g'] ?? null)) {
            return null;
        }

        return new self($row['p'], $row['u'], $row['g']);
    }
}
