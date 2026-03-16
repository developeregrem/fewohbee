<?php

declare(strict_types=1);

namespace App\Dto\ReservationTable;

use App\Entity\Appartment;

final class TableRow
{
    /**
     * @param TableCell[] $cells
     */
    public function __construct(
        public readonly Appartment $apartment,
        public readonly array $cells,
        public readonly bool $isSubRow = false,
    ) {
    }
}
