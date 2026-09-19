<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Carries a validated reservation period and its billable number of nights.
 */
final readonly class ReservationPeriod
{
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public int $nights,
    ) {
    }
}
