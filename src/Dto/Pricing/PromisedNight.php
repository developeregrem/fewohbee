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
 * The room price promised for one night: the price row it came from, its unit price and the
 * meaning of that amount (per person, per room or flat; gross or net), plus the guest lines.
 *
 * VAT rate, description and revenue account are not promised; they are read from the price row
 * when the invoice is created.
 */
final readonly class PromisedNight
{
    /**
     * @param list<PromisedLine> $lines      empty when the reservation has no guest counts
     * @param RateAdjustment|null $adjustment how price rules changed the unit price, for display
     */
    public function __construct(
        public int $priceId,
        public string $unit,
        public bool $isFlatPrice,
        public bool $isPerRoom,
        public bool $includesVat,
        public array $lines = [],
        public ?RateAdjustment $adjustment = null,
    ) {
    }

    /** @param list<PromisedLine> $lines */
    public static function fromRate(NightRate $rate, array $lines): self
    {
        return new self(
            (int) $rate->price->getId(),
            $rate->unit,
            $rate->isFlatPrice,
            $rate->isPerRoom,
            $rate->includesVat,
            $lines,
            $rate->adjustment,
        );
    }

    /**
     * The night without its date, as stored in a segment. Consecutive nights with equal
     * payloads are merged into one segment.
     *
     * @return array{p: int, u: string, t: string, g: bool, l?: list<array<int, int|string>>, d?: array<string, mixed>}
     */
    public function payload(): array
    {
        $payload = [
            'p' => $this->priceId,
            'u' => $this->unit,
            't' => $this->isFlatPrice ? 'f' : ($this->isPerRoom ? 'r' : 'p'),
            'g' => $this->includesVat,
        ];
        if ([] !== $this->lines) {
            $payload['l'] = array_map(static fn (PromisedLine $line): array => $line->toArray(), $this->lines);
        }
        if (null !== $this->adjustment) {
            $payload['d'] = $this->adjustment->toArray();
        }

        return $payload;
    }

    /** @param array<string, mixed> $segment */
    public static function fromPayload(array $segment): ?self
    {
        $type = $segment['t'] ?? null;
        if (!is_int($segment['p'] ?? null) || !is_string($segment['u'] ?? null) || !in_array($type, ['p', 'r', 'f'], true) || !is_bool($segment['g'] ?? null)) {
            return null;
        }
        $lines = [];
        foreach ((array) ($segment['l'] ?? []) as $row) {
            $line = PromisedLine::fromArray($row);
            if (null === $line) {
                return null;
            }
            $lines[] = $line;
        }

        $adjustment = null;
        if (isset($segment['d'])) {
            $adjustment = RateAdjustment::fromArray($segment['d']);
            if (null === $adjustment) {
                return null;
            }
        }

        return new self($segment['p'], $segment['u'], 'f' === $type, 'r' === $type, $segment['g'], $lines, $adjustment);
    }
}
