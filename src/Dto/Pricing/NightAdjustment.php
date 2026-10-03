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

use App\Entity\DayPrice;
use App\Entity\PriceRule;

/**
 * How one night differs from the price list: the percentage, already held within the configured
 * limits, and where it comes from - the matching price rules, or a day price that takes their
 * place on its night.
 */
final readonly class NightAdjustment
{
    /**
     * @param list<PriceRule> $rules    the matching rules, each with its own percentage; empty for a day price
     * @param bool            $limited  whether the change was cut down to the configured limits
     * @param DayPrice|null   $dayPrice the day price the percentage was derived from
     */
    public function __construct(
        public float $percent,
        public array $rules,
        public bool $limited = false,
        public ?DayPrice $dayPrice = null,
    ) {
    }
}
