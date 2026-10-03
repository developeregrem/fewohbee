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
use App\Entity\GuestCategoryModifier;

/**
 * The guest category modifier a breakdown line was priced with, kept as type and value.
 *
 * A price promise must still describe the line after the modifier itself was changed or
 * deleted, so invoices are built from this copy rather than from the entity.
 */
final readonly class GuestAdjustment
{
    public function __construct(
        public ModifierType $type,
        public float $value,
    ) {
    }

    public static function fromModifier(GuestCategoryModifier $modifier): self
    {
        return new self($modifier->getType(), $modifier->getValueAsFloat());
    }

    /** Lines with the same key are described identically and may share an invoice position. */
    public function key(): string
    {
        return $this->type->value.':'.number_format($this->value, 2, '.', '');
    }
}
