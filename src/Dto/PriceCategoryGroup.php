<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Price;
use App\Entity\RoomCategory;

/**
 * Prices that share exactly the same set of room categories, as shown together in the price
 * settings overview, split by price type. An empty category list means the prices apply to every
 * room category.
 */
final readonly class PriceCategoryGroup
{
    /**
     * @param list<RoomCategory> $roomCategories  ordered by id (creation order)
     * @param list<Price>        $apartmentPrices room prices (type 2)
     * @param list<Price>        $miscPrices      miscellaneous prices (type 1)
     */
    public function __construct(
        public array $roomCategories,
        public array $apartmentPrices,
        public array $miscPrices,
    ) {
    }
}
