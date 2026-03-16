<?php

declare(strict_types=1);

namespace App\Dto\ReservationTable;

final class DayColumn
{
    /**
     * @param string[] $holidays
     */
    public function __construct(
        public readonly string $date,
        public readonly int $dayOfMonth,
        public readonly int $isoDayOfWeek,
        public readonly bool $isWeekend,
        public readonly array $holidays = [],
    ) {
    }
}
