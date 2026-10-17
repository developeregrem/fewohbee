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

use App\Entity\Enum\DayPriceSource;
use App\Entity\PriceRule;

/**
 * How a night's unit price differs from the price list, as kept with the night and its promise:
 * the unit price before, the total percentage, and the price rules by name or the source of the
 * day price that set it. Names rather than ids, so a booking still explains its price after a
 * rule was renamed or deleted.
 */
final readonly class RateAdjustment
{
    /**
     * @param list<array{0: string, 1: float}> $rules          rule name and its percentage
     * @param string|null                      $dayPriceSource set when a day price set the night (DayPriceSource value)
     */
    public function __construct(
        public string $baseUnit,
        public float $percent,
        public array $rules,
        public ?string $dayPriceSource = null,
    ) {
    }

    public static function from(string $baseUnit, NightAdjustment $adjustment): self
    {
        return new self(
            $baseUnit,
            $adjustment->percent,
            array_map(static fn (PriceRule $rule): array => [$rule->getName(), $rule->getPercent()], $adjustment->rules),
            $adjustment->dayPrice?->getSource()->value,
        );
    }

    /** @return array{b: string, pct: string, r: list<array{0: string, 1: string}>, s?: string} */
    public function toArray(): array
    {
        $data = [
            'b' => $this->baseUnit,
            'pct' => PricePromise::money($this->percent),
            'r' => array_map(static fn (array $rule): array => [$rule[0], PricePromise::money($rule[1])], $this->rules),
        ];
        if (null !== $this->dayPriceSource) {
            $data['s'] = $this->dayPriceSource;
        }

        return $data;
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data) || !is_string($data['b'] ?? null) || !is_numeric($data['pct'] ?? null) || !is_array($data['r'] ?? null)) {
            return null;
        }
        $rules = [];
        foreach ($data['r'] as $rule) {
            if (!is_array($rule) || !is_string($rule[0] ?? null) || !is_numeric($rule[1] ?? null)) {
                return null;
            }
            $rules[] = [$rule[0], (float) $rule[1]];
        }

        $source = $data['s'] ?? null;
        if (null !== $source && null === DayPriceSource::tryFrom((string) $source)) {
            return null;
        }

        return new self($data['b'], (float) $data['pct'], $rules, $source);
    }
}
