<?php

declare(strict_types=1);

namespace App\Dto\ReservationTable;

final class TableHeader
{
    public function __construct(
        public readonly string $label,
        public readonly int $colspan,
    ) {
    }
}
