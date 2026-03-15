<?php

declare(strict_types=1);

namespace App\Dto\ReservationTable;

final class TableGrid
{
    /**
     * @param TableHeader[]  $monthHeaders
     * @param TableHeader[]  $weekHeaders
     * @param DayColumn[]    $dayColumns
     * @param TableRow[]     $rows
     * @param array<int, string|null> $subsidiaryBreaks apartment ID => subsidiary name (for group headers)
     */
    public function __construct(
        public readonly array $monthHeaders,
        public readonly array $weekHeaders,
        public readonly array $dayColumns,
        public readonly array $rows,
        public readonly array $subsidiaryBreaks = [],
    ) {
    }
}
