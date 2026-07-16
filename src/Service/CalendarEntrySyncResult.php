<?php

declare(strict_types=1);

namespace App\Service;

final readonly class CalendarEntrySyncResult
{
    public function __construct(
        public int $new,
        public int $updated,
        public int $unchanged,
    ) {
    }

    public function total(): int
    {
        return $this->new + $this->updated + $this->unchanged;
    }
}
