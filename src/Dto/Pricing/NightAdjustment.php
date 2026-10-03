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

use App\Entity\PriceRule;

/**
 * What the price rules do to one night: the summed percentage, already held within the
 * configured limits, and the rules that contributed.
 */
final readonly class NightAdjustment
{
    /**
     * @param list<PriceRule> $rules  the matching rules, each with its own percentage
     * @param bool            $limited whether the sum was cut down to the configured limits
     */
    public function __construct(
        public float $percent,
        public array $rules,
        public bool $limited = false,
    ) {
    }
}
