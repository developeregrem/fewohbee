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
 * meaning of that amount (per person, per room or flat; gross or net), plus the guest lines and,
 * for a per-room price, the surcharge for every person beyond the included ones and what each
 * guest category of them pays.
 *
 * VAT rate, description and revenue account are not promised; they are read from the price row
 * when the invoice is created.
 */
final readonly class PromisedNight
{
    /**
     * @param list<PromisedLine>      $lines                empty when the reservation has no guest counts
     * @param RateAdjustment|null     $adjustment           how price rules changed the unit price, for display
     * @param string|null             $extraPersonSurcharge charge per night for every person beyond $includedPersons
     * @param list<PromisedLine>|null $extraLines           the persons beyond $includedPersons by guest category, with
     *                                                      the surcharge each pays; null when not told apart
     */
    public function __construct(
        public int $priceId,
        public string $unit,
        public bool $isFlatPrice,
        public bool $isPerRoom,
        public bool $includesVat,
        public array $lines = [],
        public ?RateAdjustment $adjustment = null,
        public ?string $extraPersonSurcharge = null,
        public int $includedPersons = 0,
        public ?array $extraLines = null,
    ) {
    }

    /**
     * @param list<PromisedLine>      $lines
     * @param list<PromisedLine>|null $extraLines
     */
    public static function fromRate(NightRate $rate, array $lines, ?array $extraLines = null): self
    {
        return new self(
            (int) $rate->price->getId(),
            $rate->unit,
            $rate->isFlatPrice,
            $rate->isPerRoom,
            $rate->includesVat,
            $lines,
            $rate->adjustment,
            $rate->extraPersonSurcharge,
            $rate->includedPersons,
            $extraLines,
        );
    }

    /**
     * The night without its date, as stored in a segment. Consecutive nights with equal
     * payloads are merged into one segment.
     *
     * @return array{p: int, u: string, t: string, g: bool, l?: list<array<int, int|string>>, d?: array<string, mixed>, s?: string, x?: int, e?: list<array<int, int|string>>}
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
        if (null !== $this->extraPersonSurcharge) {
            $payload['s'] = $this->extraPersonSurcharge;
            $payload['x'] = $this->includedPersons;
        }
        if (null !== $this->extraLines) {
            $payload['e'] = array_map(static fn (PromisedLine $line): array => $line->toArray(), $this->extraLines);
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
        $lines = self::linesFromPayload($segment['l'] ?? []);
        // Promises made before guest categories changed the surcharge carry no extra lines:
        // every further person of them pays the full surcharge.
        $extraLines = isset($segment['e']) ? self::linesFromPayload($segment['e']) : null;
        if (null === $lines || (isset($segment['e']) && null === $extraLines)) {
            return null;
        }

        $adjustment = null;
        if (isset($segment['d'])) {
            $adjustment = RateAdjustment::fromArray($segment['d']);
            if (null === $adjustment) {
                return null;
            }
        }

        // Promises made before the surcharge existed carry neither key.
        $surcharge = $segment['s'] ?? null;
        $included = $segment['x'] ?? 0;
        if ((null !== $surcharge && !is_string($surcharge)) || !is_int($included)) {
            return null;
        }

        return new self($segment['p'], $segment['u'], 'f' === $type, 'r' === $type, $segment['g'], $lines, $adjustment, $surcharge, $included, $extraLines);
    }

    /** @return list<PromisedLine>|null null when a row is unreadable */
    private static function linesFromPayload(mixed $rows): ?array
    {
        $lines = [];
        foreach ((array) $rows as $row) {
            $line = PromisedLine::fromArray($row);
            if (null === $line) {
                return null;
            }
            $lines[] = $line;
        }

        return $lines;
    }
}
